<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'admin'
);


$user =
    current_user();


$primaryRoleLabel =
    llama_primary_role_label(
        (int)
        $user['id']
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        (int)
        $user['id']
    );


start_llama_session();


$db =
    db();


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


function human_label(
    ?string $value
): string {

    $value =
        (string) $value;


    if (
        $value === ''
    ) {

        return 'Unknown';

    }


    return ucwords(
        str_replace(
            [
                '_',
                '-',
            ],
            ' ',
            $value
        )
    );

}


function status_label(
    ?string $status
): string {

    return match (
        (string) $status
    ) {

        'draft' =>
            'Draft',

        'active' =>
            'Active',

        'featured' =>
            'Featured',

        'unlisted' =>
            'Unlisted',

        'removed' =>
            'Removed',

        'archived' =>
            'Archived',

        default =>
            human_label(
                $status
            ),

    };

}


function source_label(
    ?string $source
): string {

    return match (
        (string) $source
    ) {

        'llama-scouted' =>
            'Llama Scouted',

        'community-scouted' =>
            'Community Scouted',

        'public-source' =>
            'Public Source',

        default =>
            human_label(
                $source
            ),

    };

}


function format_date(
    ?string $date,
    bool $includeTime = false
): string {

    if (
        !$date
    ) {

        return 'Unknown';

    }


    $timestamp =
        strtotime(
            $date
        );


    if (
        $timestamp === false
    ) {

        return $date;

    }


    return date(
        $includeTime
            ? 'M j, Y g:i A'
            : 'M j, Y',
        $timestamp
    );

}


function yes_no_unknown(
    mixed $value
): string {

    if (
        $value === null
        ||
        $value === ''
    ) {

        return 'Unknown';

    }


    return
        (int) $value === 1
            ? 'Yes'
            : 'No';

}


function rating_value(
    mixed $value,
    bool $connectivity = false
): string {

    if (
        $value === null
        ||
        $value === ''
    ) {

        return 'Unknown';

    }


    $number =
        (int) $value;


    if (
        $connectivity
        &&
        $number === 0
    ) {

        return
            'No Service (0/5)';

    }


    return
        $number
        . '/5';

}


function plain_value(
    mixed $value,
    string $suffix = ''
): string {

    if (
        $value === null
        ||
        $value === ''
    ) {

        return 'Unknown';

    }


    return
        (string) $value
        . $suffix;

}


function money_value(
    mixed $value
): string {

    if (
        $value === null
        ||
        $value === ''
    ) {

        return 'Unknown';

    }


    $amount =
        (float) $value;


    if (
        $amount == 0.0
    ) {

        return 'Free';

    }


    return
        '$'
        .
        number_format(
            $amount,
            2
        );

}


function fetch_one(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare(
            $sql
        );


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


function fetch_all(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute(
        $params
    );


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

}


function table_columns(
    PDO $db,
    string $table
): array {

    static $cache = [];


    if (
        isset(
            $cache[
                $table
            ]
        )
    ) {

        return
            $cache[
                $table
            ];

    }


    $rows =
        $db
        ->query(
            "SHOW COLUMNS FROM `$table`"
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


    $columns = [];


    foreach (
        $rows as $row
    ) {

        $columns[] =
            $row[
                'Field'
            ];

    }


    $cache[
        $table
    ] =
        $columns;


    return
        $columns;

}


function has_column(
    PDO $db,
    string $table,
    string $column
): bool {

    return in_array(
        $column,
        table_columns(
            $db,
            $table
        ),
        true
    );

}


function enum_values(
    PDO $db,
    string $table,
    string $column
): array {

    $table =
        preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            $table
        );


    $column =
        preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            $column
        );


    if (
        $table === ''
        ||
        $column === ''
    ) {

        return [];

    }


    try {

        $stmt =
            $db->query(
                "SHOW COLUMNS
                 FROM `$table`
                 WHERE Field = "
                .
                $db->quote(
                    $column
                )
            );


        if (
            !$stmt
        ) {

            return [];

        }


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$row
            ||
            empty(
                $row[
                    'Type'
                ]
            )
            ||
            !preg_match(
                "/^enum\\((.*)\\)$/i",
                $row[
                    'Type'
                ],
                $matches
            )
        ) {

            return [];

        }


        return str_getcsv(
            $matches[1],
            ',',
            "'"
        );

    } catch (
        Throwable $exception
    ) {

        error_log(
            'Verification enum lookup error: '
            .
            $exception
                ->getMessage()
        );


        return [];

    }

}


/*
 * Shared display table.
 *
 * Uses our new admin.css detail classes
 * rather than the old page-specific
 * data-grid/data-row classes.
 */

function render_rows(
    array $rows
): void {

    echo
        '<div class="admin-detail-list">';


    foreach (
        $rows as
        $label =>
        $value
    ) {

        echo
            '<div class="admin-detail-row">';


        echo
            '<div class="admin-detail-label">'
            .
            e(
                $label
            )
            .
            '</div>';


        echo
            '<div class="admin-detail-value">'
            .
            e(
                $value
            )
            .
            '</div>';


        echo
            '</div>';

    }


    echo
        '</div>';

}


function person_label(
    array $row,
    string $prefix = ''
): string {

    foreach (
        [
            $prefix
            . 'display_name',

            $prefix
            . 'username',

            $prefix
            . 'email',
        ] as $field
    ) {

        if (
            !empty(
                $row[
                    $field
                ]
            )
        ) {

            return
                (string)
                $row[
                    $field
                ];

        }

    }


    return 'Unknown';

}


/* =========================================================
   PLACE ID
   ========================================================= */

$placeId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'place_id'
        ]
        ??
        0
    );


if (
    $placeId < 1
) {

    http_response_code(
        400
    );


    exit(
        'A valid place ID is required.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_place_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_place_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'admin_place_csrf'
    ];


/* =========================================================
   LOAD PLACE
   ========================================================= */

$place =
    fetch_one(
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

    http_response_code(
        404
    );


    exit(
        'Place not found.'
    );

}


/* =========================================================
   ACTION NOTICES
   ========================================================= */

$message =
    '';


$error =
    '';


/* =========================================================
   POST ACTIONS
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? 'place_status'
                )
            );


        /* =================================================
           REPORT MODERATION
           ================================================= */

        if (
            $action ===
            'update_report'
        ) {

            $reportId =
                (int) (
                    $_POST[
                        'report_id'
                    ]
                    ?? 0
                );


            $reportStatus =
                trim(
                    (string) (
                        $_POST[
                            'report_status'
                        ]
                        ?? ''
                    )
                );


            $adminNotes =
                trim(
                    (string) (
                        $_POST[
                            'admin_notes'
                        ]
                        ?? ''
                    )
                );


            $allowedReportStatuses = [
                'open',
                'investigating',
                'resolved',
                'dismissed',
            ];


            if (
                $reportId < 1
            ) {

                $error =
                    'That report could not be identified.';


            } elseif (
                !in_array(
                    $reportStatus,
                    $allowedReportStatuses,
                    true
                )
            ) {

                $error =
                    'That report status is not valid.';


            } else {

                try {

                    $db->beginTransaction();


                    /*
                     * Lock the report before changing it so two
                     * Admins cannot modify the same report at
                     * exactly the same time.
                     */

                    $reportStmt =
                        $db->prepare(
                            '
                            SELECT
                                id,
                                status

                            FROM place_reports

                            WHERE id = ?
                              AND place_id = ?

                            LIMIT 1

                            FOR UPDATE
                            '
                        );


                    $reportStmt->execute([
                        $reportId,
                        $placeId
                    ]);


                    $reportCheck =
                        $reportStmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (
                        !$reportCheck
                    ) {

                        throw new DomainException(
                            'Report does not belong to this place.'
                        );
                    }


                    $setParts = [
                        'status = ?',
                        'reviewed_by = ?',
                    ];


                    $params = [
                        $reportStatus,
                        (int)
                        $user[
                            'id'
                        ],
                    ];


                    if (
                        has_column(
                            $db,
                            'place_reports',
                            'admin_notes'
                        )
                    ) {

                        $setParts[] =
                            'admin_notes = ?';


                        $params[] =
                            $adminNotes !== ''
                                ? $adminNotes
                                : null;
                    }


                    if (
                        has_column(
                            $db,
                            'place_reports',
                            'reviewed_at'
                        )
                    ) {

                        $setParts[] =
                            'reviewed_at = CURRENT_TIMESTAMP';
                    }


                    $params[] =
                        $reportId;


                    $params[] =
                        $placeId;


                    $updateReport =
                        $db->prepare(
                            '
                            UPDATE place_reports

                            SET '
                            .
                            implode(
                                ', ',
                                $setParts
                            )
                            .
                            '

                            WHERE id = ?
                              AND place_id = ?
                            '
                        );


                    $updateReport->execute(
                        $params
                    );


                    $db->commit();


                    $message =
                        'Report #'
                        .
                        $reportId
                        .
                        ' updated.';


                } catch (
                    DomainException $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    $error =
                        $exception
                            ->getMessage();


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    error_log(
                        'Llama Scout report moderation error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The report could not be updated.';
                }
            }


        /* =================================================
           PLACE VERIFICATION
           ================================================= */
        } elseif (
            $action ===
            'verify_place'
        ) {

            $verificationType =
                trim(
                    (string) (
                        $_POST[
                            'verification_type'
                        ]
                        ?? ''
                    )
                );


            $visitedAt =
                trim(
                    (string) (
                        $_POST[
                            'visited_at'
                        ]
                        ?? ''
                    )
                );


            $verificationSource =
                trim(
                    (string) (
                        $_POST[
                            'verification_source'
                        ]
                        ?? ''
                    )
                );


            $verificationNotes =
                trim(
                    (string) (
                        $_POST[
                            'verification_notes'
                        ]
                        ?? ''
                    )
                );


            $availableVerificationTypes =
                enum_values(
                    $db,
                    'place_verifications',
                    'verification_type'
                );


            if (
                !$availableVerificationTypes
            ) {

                $availableVerificationTypes = [
                    'on-site',
                    'remote',
                    'community',
                    'admin-review',
                ];

            }


            if (
                $verificationType === ''
            ) {

                $error =
                    'Choose a verification type.';

            } elseif (
                !in_array(
                    $verificationType,
                    $availableVerificationTypes,
                    true
                )
            ) {

                $error =
                    'That verification type is not valid.';

            } elseif (
                $visitedAt !== ''
                &&
                strtotime(
                    $visitedAt
                ) === false
            ) {

                $error =
                    'The visit date is not valid.';

            } else {

                try {

                    $db->beginTransaction();


                    $verifiedAt =
                        date(
                            'Y-m-d H:i:s'
                        );


                    $insertVerification =
                        $db->prepare(
                            '
                            INSERT INTO place_verifications
                            (
                                place_id,
                                verification_type,
                                verified_at,
                                visited_at,
                                source,
                                notes,
                                verified_by
                            )

                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?
                            )
                            '
                        );


                    $insertVerification
                        ->execute([
                            $placeId,

                            $verificationType,

                            $verifiedAt,

                            $visitedAt !== ''
                                ? date(
                                    'Y-m-d',
                                    strtotime(
                                        $visitedAt
                                    )
                                )
                                : null,

                            $verificationSource !== ''
                                ? $verificationSource
                                : null,

                            $verificationNotes !== ''
                                ? $verificationNotes
                                : null,

                            $user[
                                'id'
                            ],
                        ]);


                    $updatePlaceVerification =
                        $db->prepare(
                            '
                            UPDATE places

                            SET
                                last_verified_at = ?,
                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id = ?
                            '
                        );


                    $updatePlaceVerification
                        ->execute([
                            $verifiedAt,
                            $placeId,
                        ]);


                    $db->commit();


                    $message =
                        'Place verification recorded.';


                    $place =
                        fetch_one(
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

                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();

                    }


                    error_log(
                        'Llama Scout verification error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The verification could not be saved.';

                }

            }


        /* =================================================
           PLACE STATUS
           ================================================= */

        } elseif (
            $action ===
            'place_status'
        ) {

            $newStatus =
                trim(
                    (string) (
                        $_POST[
                            'status'
                        ]
                        ?? ''
                    )
                );


            $reason =
                trim(
                    (string) (
                        $_POST[
                            'status_reason'
                        ]
                        ?? ''
                    )
                );


            $allowedStatuses = [
                'draft',
                'active',
                'featured',
                'unlisted',
                'removed',
                'archived',
            ];


            if (
                !in_array(
                    $newStatus,
                    $allowedStatuses,
                    true
                )
            ) {

                $error =
                    'That place status is not valid.';

            } elseif (
                $newStatus ===
                $place[
                    'status'
                ]
            ) {

                $error =
                    'The place is already '
                    .
                    status_label(
                        $newStatus
                    )
                    .
                    '.';

            } elseif (
                in_array(
                    $newStatus,
                    [
                        'unlisted',
                        'removed',
                        'archived',
                    ],
                    true
                )
                &&
                $reason === ''
            ) {

                $error =
                    'Add a reason before unlisting, removing, or archiving a place.';

            } else {

                try {

                    $db->beginTransaction();


                    $oldStatus =
                        $place[
                            'status'
                        ];


                    $update =
                        $db->prepare(
                            '
                            UPDATE places

                            SET
                                status = ?,
                                status_reason = ?,
                                status_changed_at =
                                    CURRENT_TIMESTAMP,
                                status_changed_by = ?

                            WHERE id = ?
                            '
                        );


                    $update->execute([
                        $newStatus,

                        $reason !== ''
                            ? $reason
                            : null,

                        $user[
                            'id'
                        ],

                        $placeId,
                    ]);


                    $history =
                        $db->prepare(
                            '
                            INSERT INTO
                                place_status_history
                            (
                                place_id,
                                old_status,
                                new_status,
                                reason,
                                changed_by
                            )

                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                ?
                            )
                            '
                        );


                    $history->execute([
                        $placeId,
                        $oldStatus,
                        $newStatus,

                        $reason !== ''
                            ? $reason
                            : null,

                        $user[
                            'id'
                        ],
                    ]);


                    $db->commit();


                    $message =
                        'Place changed from '
                        .
                        status_label(
                            $oldStatus
                        )
                        .
                        ' to '
                        .
                        status_label(
                            $newStatus
                        )
                        .
                        '.';


                    $place =
                        fetch_one(
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

                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();

                    }


                    error_log(
                        'Llama Scout place status error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The place status could not be updated.';

                }

            }

        } else {

            $error =
                'That admin action is not supported.';

        }

    }

}


/* =========================================================
   RELATED PLACE DATA
   ========================================================= */

$details =
    fetch_one(
        $db,
        '
        SELECT *

        FROM place_details

        WHERE place_id = ?
        ',
        [
            $placeId
        ]
    );


$sensoryDetails =
    fetch_one(
        $db,
        '
        SELECT *

        FROM place_sensory_details

        WHERE place_id = ?
        ',
        [
            $placeId
        ]
    );


$connectivity =
    fetch_one(
        $db,
        '
        SELECT *

        FROM place_connectivity

        WHERE place_id = ?
        ',
        [
            $placeId
        ]
    );


$amenities =
    fetch_one(
        $db,
        '
        SELECT *

        FROM place_amenities

        WHERE place_id = ?
        ',
        [
            $placeId
        ]
    );


$experience =
    fetch_one(
        $db,
        '
        SELECT *

        FROM place_experience

        WHERE place_id = ?
        ',
        [
            $placeId
        ]
    );


$rules =
    fetch_one(
        $db,
        '
        SELECT *

        FROM place_rules

        WHERE place_id = ?
        ',
        [
            $placeId
        ]
    );


/* =========================================================
   SENSORY PERIODS
   ========================================================= */

$sensoryRows =
    fetch_all(
        $db,
        '
        SELECT *

        FROM place_sensory

        WHERE place_id = ?

        ORDER BY
            CASE period
                WHEN "daytime" THEN 1
                WHEN "nighttime" THEN 2
                ELSE 3
            END
        ',
        [
            $placeId
        ]
    );


$sensory = [];


foreach (
    $sensoryRows as $row
) {

    $sensory[
        $row[
            'period'
        ]
    ] =
        $row;

}


/* =========================================================
   IMAGES
   ========================================================= */

$images =
    fetch_all(
        $db,
        '
        SELECT *

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


/* =========================================================
   NOTES
   ========================================================= */

$notes =
    fetch_all(
        $db,
        '
        SELECT *

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


/* =========================================================
   VERIFICATION HISTORY
   ========================================================= */

$verifications =
    fetch_all(
        $db,
        '
        SELECT
            pv.*,
            u.username,
            u.display_name,
            u.email

        FROM place_verifications pv

        LEFT JOIN users u
          ON u.id =
             pv.verified_by

        WHERE pv.place_id = ?

        ORDER BY
            pv.verified_at DESC,
            pv.id DESC
        ',
        [
            $placeId
        ]
    );


/* =========================================================
   STATUS HISTORY
   ========================================================= */

$statusHistory =
    fetch_all(
        $db,
        '
        SELECT
            h.*,
            u.username,
            u.display_name,
            u.email

        FROM place_status_history h

        LEFT JOIN users u
          ON u.id =
             h.changed_by

        WHERE h.place_id = ?

        ORDER BY
            h.changed_at DESC,
            h.id DESC
        ',
        [
            $placeId
        ]
    );


/* =========================================================
   PROBLEM REPORTS
   ========================================================= */

$reports =
    fetch_all(
        $db,
        '
        SELECT
            pr.*,

            reporter.username
                AS reporter_username,

            reporter.display_name
                AS reporter_display_name,

            reporter.email
                AS reporter_email,

            reviewer.username
                AS reviewer_username,

            reviewer.display_name
                AS reviewer_display_name,

            reviewer.email
                AS reviewer_email

        FROM place_reports pr

        JOIN users reporter
          ON reporter.id =
             pr.user_id

        LEFT JOIN users reviewer
          ON reviewer.id =
             pr.reviewed_by

        WHERE pr.place_id = ?

        ORDER BY
            CASE pr.status
                WHEN "open" THEN 1
                WHEN "investigating" THEN 2
                WHEN "resolved" THEN 3
                WHEN "dismissed" THEN 4
                ELSE 5
            END,

            pr.created_at DESC,
            pr.id DESC
        ',
        [
            $placeId
        ]
    );


/* =========================================================
   REPORT IMAGES
   ========================================================= */

$reportImages = [];


if (
    $reports
) {

    $reportIds =
        array_map(
            static fn(
                array $report
            ): int =>
                (int)
                $report[
                    'id'
                ],
            $reports
        );


    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count(
                    $reportIds
                ),
                '?'
            )
        );


    $reportImageRows =
        fetch_all(
            $db,
            "
            SELECT
                id,
                report_id,
                file_path,
                original_name,
                mime_type,
                file_size,
                sort_order,
                created_at

            FROM place_report_images

            WHERE report_id
              IN ($placeholders)

            ORDER BY
                report_id ASC,
                sort_order ASC,
                id ASC
            ",
            $reportIds
        );


    foreach (
        $reportImageRows as
        $reportImage
    ) {

        $reportId =
            (int)
            $reportImage[
                'report_id'
            ];


        if (
            !isset(
                $reportImages[
                    $reportId
                ]
            )
        ) {

            $reportImages[
                $reportId
            ] =
                [];

        }


        $reportImages[
            $reportId
        ][] =
            $reportImage;

    }

}


/* =========================================================
   OPEN REPORT COUNT
   ========================================================= */

$openReports =
    0;


foreach (
    $reports as $report
) {

    if (
        in_array(
            $report[
                'status'
            ],
            [
                'open',
                'investigating',
            ],
            true
        )
    ) {

        $openReports++;

    }

}


/* =========================================================
   VERIFICATION TYPES
   ========================================================= */

$verificationTypes =
    enum_values(
        $db,
        'place_verifications',
        'verification_type'
    );


if (
    !$verificationTypes
) {

    $verificationTypes = [
        'on-site',
        'remote',
        'community',
        'admin-review',
    ];

}


/* =========================================================
   DISPLAY DATA GROUPS
   ========================================================= */

$overviewRows = [

    'Slug' =>
        plain_value(
            $place[
                'slug'
            ]
            ?? null
        ),

    'Type' =>
        human_label(
            $place[
                'type'
            ]
            ?? null
        ),

    'Source' =>
        source_label(
            $place[
                'source_type'
            ]
            ?? null
        ),

    'Published' =>
        format_date(
            $place[
                'published_at'
            ]
            ?? null
        ),

    'Created' =>
        format_date(
            $place[
                'created_at'
            ]
            ?? null
        ),

];


$locationRows = [

    'Latitude' =>
        plain_value(
            $place[
                'latitude'
            ]
            ?? null
        ),

    'Longitude' =>
        plain_value(
            $place[
                'longitude'
            ]
            ?? null
        ),

    'Elevation' =>
        plain_value(
            $place[
                'elevation_feet'
            ]
            ?? null,
            ' ft'
        ),

    'Road' =>
        plain_value(
            $place[
                'road'
            ]
            ?? null
        ),

    'City' =>
        plain_value(
            $place[
                'city'
            ]
            ?? null
        ),

    'County' =>
        plain_value(
            $place[
                'county'
            ]
            ?? null
        ),

    'State' =>
        plain_value(
            $place[
                'state'
            ]
            ?? null
        ),

    'Region' =>
        plain_value(
            $place[
                'region'
            ]
            ?? null
        ),

    'Land Manager' =>
        plain_value(
            $place[
                'land_manager'
            ]
            ?? null
        ),

    'Land Type' =>
        plain_value(
            $place[
                'land_type'
            ]
            ?? null
        ),

];


$siteRows = [

    'Vehicle Capacity' =>
        plain_value(
            $details[
                'vehicle_capacity'
            ]
            ?? null
        ),

    'Max Vehicle Length' =>
        plain_value(
            $details[
                'max_vehicle_length_feet'
            ]
            ?? null,
            ' ft'
        ),

    'Tent Camping' =>
        yes_no_unknown(
            $details[
                'tent_camping_suitable'
            ]
            ?? null
        ),

    'RV Suitable' =>
        yes_no_unknown(
            $details[
                'rv_suitable'
            ]
            ?? null
        ),

    'Trailer Suitable' =>
        yes_no_unknown(
            $details[
                'trailer_suitable'
            ]
            ?? null
        ),

    'Parking Surface' =>
        plain_value(
            $details[
                'parking_surface'
            ]
            ?? null
        ),

    'Levelness' =>
        rating_value(
            $details[
                'levelness'
            ]
            ?? null
        ),

    'Leveling Required' =>
        yes_no_unknown(
            $details[
                'leveling_required'
            ]
            ?? null
        ),

    'Turnaround Space' =>
        yes_no_unknown(
            $details[
                'turnaround_space'
            ]
            ?? null
        ),

    'Pull Through' =>
        yes_no_unknown(
            $details[
                'pull_through'
            ]
            ?? null
        ),

    'Back In' =>
        yes_no_unknown(
            $details[
                'back_in'
            ]
            ?? null
        ),

    'Ground Condition' =>
        plain_value(
            $details[
                'ground_condition'
            ]
            ?? null
        ),

    'Open Sky' =>
        rating_value(
            $details[
                'site_open_sky'
            ]
            ?? null
        ),

    'Tree Cover' =>
        rating_value(
            $details[
                'tree_cover'
            ]
            ?? null
        ),

    'Shade' =>
        rating_value(
            $details[
                'site_shade'
            ]
            ?? null
        ),

];


$roadRows = [

    'Site Access Difficulty' =>
        rating_value(
            $details[
                'site_access_difficulty'
            ]
            ?? null
        ),

    'Road Difficulty' =>
        rating_value(
            $details[
                'road_overall_difficulty'
            ]
            ?? null
        ),

    'Road Stress' =>
        rating_value(
            $details[
                'road_stress'
            ]
            ?? null
        ),

    'Sedan Accessible' =>
        yes_no_unknown(
            $details[
                'sedan_accessible'
            ]
            ?? null
        ),

    'High Clearance Recommended' =>
        yes_no_unknown(
            $details[
                'high_clearance_recommended'
            ]
            ?? null
        ),

    '4WD Recommended' =>
        yes_no_unknown(
            $details[
                'four_wheel_drive_recommended'
            ]
            ?? null
        ),

    'Road Surface' =>
        plain_value(
            $details[
                'road_surface'
            ]
            ?? null
        ),

    'Road Width' =>
        plain_value(
            $details[
                'road_width'
            ]
            ?? null
        ),

    'Rocks' =>
        rating_value(
            $details[
                'rocks'
            ]
            ?? null
        ),

    'Washboards' =>
        rating_value(
            $details[
                'washboards'
            ]
            ?? null
        ),

    'Potholes' =>
        rating_value(
            $details[
                'potholes'
            ]
            ?? null
        ),

    'Mud Risk' =>
        rating_value(
            $details[
                'mud_risk'
            ]
            ?? null
        ),

    'Steep Grades' =>
        rating_value(
            $details[
                'steep_grades'
            ]
            ?? null
        ),

    'Drop-Off Exposure' =>
        rating_value(
            $details[
                'drop_off_exposure'
            ]
            ?? null
        ),

    'Water Crossings' =>
        yes_no_unknown(
            $details[
                'water_crossings'
            ]
            ?? null
        ),

    'Downed Tree Risk' =>
        yes_no_unknown(
            $details[
                'downed_tree_risk'
            ]
            ?? null
        ),

    'Seasonal Closure' =>
        yes_no_unknown(
            $details[
                'seasonal_closure'
            ]
            ?? null
        ),

];


$connectivityRows = [

    'Overall Cell Service' =>
        rating_value(
            $connectivity[
                'overall'
            ]
            ?? null,
            true
        ),

    'T-Mobile' =>
        rating_value(
            $connectivity[
                't_mobile'
            ]
            ?? null,
            true
        ),

    'Verizon' =>
        rating_value(
            $connectivity[
                'verizon'
            ]
            ?? null,
            true
        ),

    'AT&T' =>
        rating_value(
            $connectivity[
                'att'
            ]
            ?? null,
            true
        ),

    'Other Cell' =>
        rating_value(
            $connectivity[
                'other_cell'
            ]
            ?? null,
            true
        ),

    'Starlink' =>
        rating_value(
            $connectivity[
                'starlink'
            ]
            ?? null,
            true
        ),

    'Starlink Actually Tested' =>
        yes_no_unknown(
            $connectivity[
                'starlink_tested'
            ]
            ?? null
        ),

    'Starlink Notes' =>
        plain_value(
            $connectivity[
                'starlink_note'
            ]
            ?? null
        ),

];


$amenityRows = [];


foreach (
    [
        'toilets' =>
            'Toilets',

        'potable_water' =>
            'Potable Water',

        'trash' =>
            'Trash',

        'fire_ring' =>
            'Fire Ring',

        'picnic_table' =>
            'Picnic Table',

        'bear_box' =>
            'Bear Box',

        'showers' =>
            'Showers',

        'electricity' =>
            'Electricity',

        'dump_station' =>
            'Dump Station',

        'food_storage_required' =>
            'Food Storage Required',

    ] as
    $field =>
    $label
) {

    $amenityRows[
        $label
    ] =
        yes_no_unknown(
            $amenities[
                $field
            ]
            ?? null
        );

}


/* =========================================================
   ENVIRONMENT
   ========================================================= */

$environmentRows = [];


foreach (
    [
        'forest' =>
            'Forest',

        'mountains' =>
            'Mountains',

        'water_nearby' =>
            'Water Nearby',

        'water_view' =>
            'Water View',

        'mountain_view' =>
            'Mountain View',

        'forest_view' =>
            'Forest View',

        'wildlife' =>
            'Wildlife',

        'bugs' =>
            'Bugs',

    ] as
    $field =>
    $label
) {

    $environmentRows[
        $label
    ] =
        yes_no_unknown(
            $details[
                $field
            ]
            ?? null
        );

}


foreach (
    [
        'wind_exposure' =>
            'Wind Exposure',

        'sun_exposure' =>
            'Sun Exposure',

        'environment_shade' =>
            'Shade',

        'environment_open_sky' =>
            'Open Sky',

    ] as
    $field =>
    $label
) {

    $environmentRows[
        $label
    ] =
        rating_value(
            $details[
                $field
            ]
            ?? null
        );

}


/* =========================================================
   ACCESSIBILITY
   ========================================================= */

$accessibilityRows = [];


foreach (
    [
        'wheelchair_friendly' =>
            'Wheelchair Friendly',

        'mobility_device_friendly' =>
            'Mobility Device Friendly',

        'flat_walking_surface' =>
            'Flat Walking Surface',

        'step_free_access' =>
            'Step-Free Access',

        'accessible_toilet' =>
            'Accessible Toilet',

        'accessible_picnic_table' =>
            'Accessible Picnic Table',

    ] as
    $field =>
    $label
) {

    $accessibilityRows[
        $label
    ] =
        yes_no_unknown(
            $details[
                $field
            ]
            ?? null
        );

}


$accessibilityRows[
    'Walking Distance From Vehicle'
] =
    plain_value(
        $details[
            'walking_distance_from_vehicle'
        ]
        ?? null
    );


/* =========================================================
   SAFETY
   ========================================================= */

$safetyRows = [];


foreach (
    [
        'felt_safe_daytime' =>
            'Felt Safe Daytime',

        'felt_safe_nighttime' =>
            'Felt Safe Nighttime',

        'flash_flood_risk' =>
            'Flash Flood Risk',

        'wildfire_risk' =>
            'Wildfire Risk',

        'fall_hazard' =>
            'Fall Hazard',

        'cliff_exposure' =>
            'Cliff Exposure',

        'rockfall_risk' =>
            'Rockfall Risk',

        'wildlife_risk' =>
            'Wildlife Risk',

        'traffic_hazard' =>
            'Traffic Hazard',

        'emergency_access' =>
            'Emergency Access',

    ] as
    $field =>
    $label
) {

    $safetyRows[
        $label
    ] =
        yes_no_unknown(
            $details[
                $field
            ]
            ?? null
        );

}


/* =========================================================
   EXPERIENCE
   ========================================================= */

$experienceRows = [];


foreach (
    [
        'sunrise_view' =>
            'Sunrise View',

        'sunset_view' =>
            'Sunset View',

        'mountain_view' =>
            'Mountain View',

        'forest_view' =>
            'Forest View',

        'night_sky' =>
            'Night Sky',

        'stargazing' =>
            'Stargazing',

        'quiet_evening' =>
            'Quiet Evening',

        'overnight_comfort' =>
            'Overnight Comfort',

        'extended_stay_comfort' =>
            'Extended Stay Comfort',

        'sensory_retreat' =>
            'Sensory Retreat',

        'remote_work' =>
            'Remote Work',

        'overall_scenery' =>
            'Overall Scenery',

    ] as
    $field =>
    $label
) {

    $experienceRows[
        $label
    ] =
        rating_value(
            $experience[
                $field
            ]
            ?? null
        );

}


/* =========================================================
   RECOMMENDATIONS
   ========================================================= */

$recommendedRows = [];


foreach (
    [
        'recommended_overnight_stop' =>
            'Overnight Stop',

        'recommended_quiet_evening' =>
            'Quiet Evening',

        'recommended_extended_stay' =>
            'Extended Stay',

        'recommended_sensory_retreat' =>
            'Sensory Retreat',

        'recommended_stargazing' =>
            'Stargazing',

        'recommended_remote_work' =>
            'Remote Work',

    ] as
    $field =>
    $label
) {

    $recommendedRows[
        $label
    ] =
        rating_value(
            $experience[
                $field
            ]
            ?? null
        );

}


$recommendedRows[
    'Solo Travel'
] =
    yes_no_unknown(
        $experience[
            'recommended_solo_travel'
        ]
        ?? null
    );


$recommendedRows[
    'Families'
] =
    yes_no_unknown(
        $experience[
            'recommended_families'
        ]
        ?? null
    );


$recommendedRows[
    'Large Groups'
] =
    yes_no_unknown(
        $experience[
            'recommended_large_groups'
        ]
        ?? null
    );


$recommendedRows[
    'Not Recommended For'
] =
    plain_value(
        $experience[
            'not_recommended_for'
        ]
        ?? null
    );


/* =========================================================
   SEASON
   ========================================================= */

$seasonRows = [

    'Best Months' =>
        plain_value(
            $rules[
                'best_months'
            ]
            ?? null
        ),

    'Winter Access' =>
        yes_no_unknown(
            $rules[
                'winter_access'
            ]
            ?? null
        ),

    'Snow Risk' =>
        rating_value(
            $rules[
                'snow_risk'
            ]
            ?? null
        ),

    'Mud Season Risk' =>
        rating_value(
            $rules[
                'mud_season_risk'
            ]
            ?? null
        ),

    'Monsoon Risk' =>
        rating_value(
            $rules[
                'monsoon_risk'
            ]
            ?? null
        ),

    'Recommended Travel Season' =>
        plain_value(
            $rules[
                'recommended_travel_season'
            ]
            ?? null
        ),

    'Seasonal Access Notes' =>
        plain_value(
            $rules[
                'seasonal_access_note'
            ]
            ?? null
        ),

];


/* =========================================================
   REGULATIONS
   ========================================================= */

$regulationRows = [

    'Overnight Camping Allowed' =>
        yes_no_unknown(
            $rules[
                'overnight_camping_allowed'
            ]
            ?? null
        ),

    'Dispersed Camping Allowed' =>
        yes_no_unknown(
            $rules[
                'dispersed_camping_allowed'
            ]
            ?? null
        ),

    'Stay Limit' =>
        plain_value(
            $rules[
                'stay_limit_days'
            ]
            ?? null,
            ' days'
        ),

    'Maximum Days per 60 Days' =>
        plain_value(
            $rules[
                'maximum_days_per_60_day_period'
            ]
            ?? null,
            ' days'
        ),

    'Required Move Distance' =>
        plain_value(
            $rules[
                'move_distance_after_stay_miles'
            ]
            ?? null,
            ' miles'
        ),

    'Permit Required' =>
        yes_no_unknown(
            $rules[
                'permit_required'
            ]
            ?? null
        ),

    'Fee' =>
        money_value(
            $rules[
                'fee'
            ]
            ?? null
        ),

    'Campfire Allowed' =>
        yes_no_unknown(
            $rules[
                'campfire_allowed'
            ]
            ?? null
        ),

    'Fire Restrictions URL' =>
        plain_value(
            $rules[
                'current_fire_restrictions_url'
            ]
            ?? null
        ),

];


/* =========================================================
   LAND USE
   ========================================================= */

$landUseRows = [

    'Vehicle Distance From Road Max' =>
        plain_value(
            $rules[
                'vehicle_distance_from_road_max_feet'
            ]
            ?? null,
            ' ft'
        ),

    'Minimum Distance From Water' =>
        plain_value(
            $rules[
                'minimum_distance_from_water_feet'
            ]
            ?? null,
            ' ft'
        ),

    'Existing Sites Encouraged' =>
        yes_no_unknown(
            $rules[
                'existing_sites_encouraged'
            ]
            ?? null
        ),

    'Pack It In / Pack It Out' =>
        yes_no_unknown(
            $rules[
                'pack_it_in_pack_it_out'
            ]
            ?? null
        ),

    'Residential Use Prohibited' =>
        yes_no_unknown(
            $rules[
                'residential_use_prohibited'
            ]
            ?? null
        ),

];


/* =========================================================
   NEARBY SERVICES
   ========================================================= */

$nearbyRows = [];


foreach (
    [
        'nearest_town' =>
            'Nearest Town',

        'nearest_fuel' =>
            'Nearest Fuel',

        'nearest_grocery' =>
            'Nearest Grocery',

        'nearest_water' =>
            'Nearest Water',

        'nearest_toilet' =>
            'Nearest Toilet',

        'nearest_hospital' =>
            'Nearest Hospital',

    ] as
    $field =>
    $label
) {

    $nearbyRows[
        $label
    ] =
        plain_value(
            $rules[
                $field
            ]
            ?? null
        );

}


/* =========================================================
   OTHER SENSORY CONDITIONS
   ========================================================= */

$otherSensoryRows = [];


foreach (
    [
        'dust_from_traffic' =>
            'Dust From Traffic',

        'generator_noise' =>
            'Generator Noise',

        'aircraft_noise' =>
            'Aircraft Noise',

        'road_noise' =>
            'Road Noise',

        'human_activity' =>
            'Human Activity',

        'wildlife_noise' =>
            'Wildlife Noise',

        'wind_noise' =>
            'Wind Noise',

        'smoke_risk' =>
            'Smoke Risk',

        'strong_odors' =>
            'Strong Odors',

        'visual_exposure' =>
            'Visual Exposure',

        'predictability' =>
            'Predictability',

    ] as
    $field =>
    $label
) {

    $otherSensoryRows[
        $label
    ] =
        rating_value(
            $sensoryDetails[
                $field
            ]
            ?? null
        );

}


/* =========================================================
   PAGE DISPLAY VALUES
   ========================================================= */

$locationText =
    trim(
        implode(
            ', ',
            array_filter([
                $place[
                    'city'
                ]
                ?? '',

                $place[
                    'state'
                ]
                ?? '',
            ])
        )
    );


$locationText =
    $locationText !== ''
        ? $locationText
        : 'Unknown';


?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

<title>
  <?= e(
      $place[
          'name'
      ]
  ) ?>
  | Llama Scout Basecamp
</title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >


  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap"
    rel="stylesheet"
  >


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/admin.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="https://llamascout.com/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="https://llamascout.com/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="https://llamascout.com/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="https://llamascout.com/icons/site.webmanifest"
  >

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <!-- =====================================================
       PAGE INTRO
       ===================================================== -->

  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">

          <i
            class="<?= e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Llama Scout
          <?= e(
              $primaryRoleLabel
          ) ?>

        </p>

        <h1>
          <?= e(
              $place[
                  'name'
              ]
          ) ?>
        </h1>

        <p>

          <?= e(
              source_label(
                  $place[
                      'source_type'
                  ]
              )
          ) ?>

          &middot;

          <?= e(
              human_label(
                  $place[
                      'type'
                  ]
              )
          ) ?>

          &middot;

          Place
          #<?= (int)
              $place[
                  'id'
              ]
          ?>

        </p>

      </div>


      <div class="admin-intro-actions">

        <span
          class="
            admin-place-status
            admin-place-status--<?= e(
                $place[
                    'status'
                ]
            ) ?>
          "
        >

          <?= e(
              status_label(
                  $place[
                      'status'
                  ]
              )
          ) ?>

        </span>


        <a
          class="
            admin-button
            admin-button--secondary
          "

          href="https://llamascout.com/place.php?place=<?= rawurlencode(
              (string)
              $place[
                  'slug'
              ]
          ) ?>"

          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>

          Public Page

        </a>

      </div>

    </div>

  </section>

<section class="admin-card">

  <div class="admin-card-header">

    <div>

      <h2>
        Place Content
      </h2>

      <p>
        Edit the information used by the public
        Llama Scout listing.
      </p>

    </div>


    <a
      class="admin-button admin-button--primary"
      href="https://account.llamascout.com/scout-place.php?admin_place=<?= (int)
          $placeId
      ?>"
    >

      <i
        class="fa-solid fa-pen-to-square"
        aria-hidden="true"
      ></i>

      Edit Place

    </a>

  </div>

</section>

    
<!-- =====================================================
     BASECAMP NAVIGATION
     ===================================================== -->

<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <!-- =====================================================
       NOTICES
       ===================================================== -->

  <?php if (
      $message
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>
        <?= e(
            $message
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <?php if (
      $error
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >

      <p>
        <?= e(
            $error
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       SUMMARY STATS
       ===================================================== -->

  <section
    class="admin-stats"
    aria-label="Place summary"
  >


    <article class="admin-stat">

      <span class="admin-stat-label">
        Location
      </span>

      <strong
        class="admin-stat-value admin-place-summary-value"
      >
        <?= e(
            $locationText
        ) ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Last Verified
      </span>

      <strong
        class="admin-stat-value admin-place-summary-value"
      >

        <?= e(
            format_date(
                $place[
                    'last_verified_at'
                ]
                ?? null
            )
        ) ?>

      </strong>

    </article>


    <article
      class="
        admin-stat
        <?= $openReports > 0
            ? 'admin-stat--alert'
            : ''
        ?>
      "
    >

      <span class="admin-stat-label">
        Open Reports
      </span>

      <strong class="admin-stat-value">
        <?= $openReports ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Updated
      </span>

      <strong
        class="admin-stat-value admin-place-summary-value"
      >

        <?= e(
            format_date(
                $place[
                    'updated_at'
                ]
                ?? null
            )
        ) ?>

      </strong>

    </article>


  </section>


  <!-- =====================================================
       MAIN LAYOUT
       ===================================================== -->

  <div class="admin-detail-grid">


    <!-- ===================================================
         MAIN COLUMN
         =================================================== -->

    <div class="admin-detail-main">


      <!-- ===============================================
           OVERVIEW
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Overview
            </h2>

          </div>

        </div>


        <?php
        render_rows(
            $overviewRows
        );
        ?>


        <?php if (
            !empty(
                $place[
                    'description'
                ]
            )
        ): ?>

          <section class="admin-section">

            <div class="admin-section-header">

              <h2>
                Description
              </h2>

            </div>

            <p>
              <?= nl2br(
                  e(
                      $place[
                          'description'
                      ]
                  )
              ) ?>
            </p>

          </section>

        <?php endif; ?>


        <?php if (
            !empty(
                $place[
                    'sensory_summary'
                ]
            )
        ): ?>

          <section class="admin-section">

            <div class="admin-section-header">

              <h2>
                Sensory Summary
              </h2>

            </div>

            <p>
              <?= nl2br(
                  e(
                      $place[
                          'sensory_summary'
                      ]
                  )
              ) ?>
            </p>

          </section>

        <?php endif; ?>


        <?php if (
            !empty(
                $place[
                    'access_summary'
                ]
            )
        ): ?>

          <section class="admin-section">

            <div class="admin-section-header">

              <h2>
                Access Summary
              </h2>

            </div>

            <p>
              <?= nl2br(
                  e(
                      $place[
                          'access_summary'
                      ]
                  )
              ) ?>
            </p>

          </section>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           LOCATION
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Location
          </h2>

        </div>

        <?php
        render_rows(
            $locationRows
        );
        ?>

      </section>


      <!-- ===============================================
           SITE AND ROAD
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Site &amp; Road Access
          </h2>

        </div>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Site
            </h2>

          </div>

          <?php
          render_rows(
              $siteRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Road Access
            </h2>

          </div>

          <?php
          render_rows(
              $roadRows
          );
          ?>

        </section>

      </section>


      <!-- ===============================================
           SENSORY
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Sensory Profile
          </h2>

        </div>


        <?php foreach (
            [
                'daytime' =>
                    'Daytime',

                'nighttime' =>
                    'Nighttime',

            ] as
            $periodKey =>
            $periodLabel
        ): ?>


          <?php

          $period =
              $sensory[
                  $periodKey
              ]
              ?? [];


          $periodRows = [

              'Noise' =>
                  rating_value(
                      $period[
                          'noise'
                      ]
                      ?? null
                  ),

              'Traffic' =>
                  rating_value(
                      $period[
                          'traffic'
                      ]
                      ?? null
                  ),

              'Crowds' =>
                  rating_value(
                      $period[
                          'crowds'
                      ]
                      ?? null
                  ),

              'Privacy' =>
                  rating_value(
                      $period[
                          'privacy'
                      ]
                      ?? null
                  ),

              'Light Pollution' =>
                  rating_value(
                      $period[
                          'light_pollution'
                      ]
                      ?? null
                  ),

              'Sensory Comfort' =>
                  rating_value(
                      $period[
                          'sensory_comfort'
                      ]
                      ?? null
                  ),

              'Social Interaction Likelihood' =>
                  rating_value(
                      $period[
                          'social_interaction_likelihood'
                      ]
                      ?? null
                  ),

          ];

          ?>


          <section class="admin-section">

            <div class="admin-section-header">

              <h2>
                <?= e(
                    $periodLabel
                ) ?>
              </h2>

            </div>

            <?php
            render_rows(
                $periodRows
            );
            ?>

          </section>


        <?php endforeach; ?>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Other Sensory Conditions
            </h2>

          </div>

          <?php
          render_rows(
              $otherSensoryRows
          );
          ?>

        </section>


      </section>


      <!-- ===============================================
           CONNECTIVITY
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Connectivity
          </h2>

        </div>

        <?php
        render_rows(
            $connectivityRows
        );
        ?>

      </section>


      <!-- ===============================================
           AMENITIES
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Amenities
          </h2>

        </div>

        <?php
        render_rows(
            $amenityRows
        );
        ?>

      </section>


      <!-- ===============================================
           ENVIRONMENT / ACCESSIBILITY / SAFETY
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Environment, Accessibility &amp; Safety
          </h2>

        </div>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Environment
            </h2>

          </div>

          <?php
          render_rows(
              $environmentRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Accessibility
            </h2>

          </div>

          <?php
          render_rows(
              $accessibilityRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Safety
            </h2>

          </div>

          <?php
          render_rows(
              $safetyRows
          );
          ?>

        </section>


      </section>


      <!-- ===============================================
           EXPERIENCE
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Experience &amp; Recommendations
          </h2>

        </div>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Experience
            </h2>

          </div>

          <?php
          render_rows(
              $experienceRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Recommended For
            </h2>

          </div>

          <?php
          render_rows(
              $recommendedRows
          );
          ?>

        </section>


      </section>


      <!-- ===============================================
           SEASON / RULES / NEARBY
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Season, Regulations &amp; Nearby Services
          </h2>

        </div>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Season
            </h2>

          </div>

          <?php
          render_rows(
              $seasonRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Regulations
            </h2>

          </div>

          <?php
          render_rows(
              $regulationRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Land Use
            </h2>

          </div>

          <?php
          render_rows(
              $landUseRows
          );
          ?>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <h2>
              Nearby
            </h2>

          </div>

          <?php
          render_rows(
              $nearbyRows
          );
          ?>

        </section>


      </section>


      <!-- ===============================================
           PLACE IMAGES
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Images
            </h2>

            <p>
              Images currently stored for this place.
            </p>

          </div>

        </div>


        <?php if (
            $images
        ): ?>

          <div class="admin-grid">

            <?php foreach (
                $images as $image
            ): ?>

              <article class="admin-card">

                <img
                  src="https://llamascout.com/<?= e(
                      ltrim(
                          (string)
                          $image[
                              'src'
                          ],
                          '/'
                      )
                  ) ?>"

                  alt="<?= e(
                      $image[
                          'alt_text'
                      ]
                      ?: $place[
                          'name'
                      ]
                  ) ?>"

                  style="
                    display:block;
                    width:100%;
                    aspect-ratio:4/3;
                    object-fit:cover;
                    border-radius:8px;
                    margin-bottom:14px;
                  "
                >


                <p>

                  <?= e(
                      $image[
                          'alt_text'
                      ]
                      ?: 'No alt text'
                  ) ?>

                </p>


                <?php if (
                    (int)
                    $image[
                        'is_featured'
                    ] === 1
                ): ?>

                  <div
                    style="margin-top:10px;"
                  >

                    <span
                      class="
                        admin-badge
                        admin-badge--success
                      "
                    >
                      Featured Image
                    </span>

                  </div>

                <?php endif; ?>


              </article>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              No images stored for this place.
            </p>

          </div>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           FIELD NOTES
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Field Notes
          </h2>

        </div>


        <?php if (
            $notes
        ): ?>

          <div class="admin-detail-list">

            <?php foreach (
                $notes as $note
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  Note
                  #<?= (int)
                      $note[
                          'id'
                      ]
                  ?>

                </div>

                <div class="admin-detail-value">

                  <?= nl2br(
                      e(
                          $note[
                              'note'
                          ]
                      )
                  ) ?>

                </div>

              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              No field notes stored.
            </p>

          </div>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           VERIFICATION HISTORY
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Verification History
          </h2>

        </div>


        <?php if (
            $verifications
        ): ?>

          <div class="admin-detail-list">

            <?php foreach (
                $verifications as
                $verification
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      human_label(
                          $verification[
                              'verification_type'
                          ]
                      )
                  ) ?>

                </div>


                <div class="admin-detail-value">

                  <strong>
                    <?= e(
                        format_date(
                            $verification[
                                'verified_at'
                            ],
                            true
                        )
                    ) ?>
                  </strong>


                  <?php if (
                      !empty(
                          $verification[
                              'visited_at'
                          ]
                      )
                  ): ?>

                    <br>

                    <span class="admin-muted">
                      Visited:
                    </span>

                    <?= e(
                        format_date(
                            $verification[
                                'visited_at'
                            ]
                        )
                    ) ?>

                  <?php endif; ?>


                  <?php if (
                      !empty(
                          $verification[
                              'source'
                          ]
                      )
                  ): ?>

                    <br>

                    <span class="admin-muted">
                      Source:
                    </span>

                    <?= e(
                        $verification[
                            'source'
                        ]
                    ) ?>

                  <?php endif; ?>


                  <?php if (
                      !empty(
                          $verification[
                              'display_name'
                          ]
                      )
                      ||
                      !empty(
                          $verification[
                              'username'
                          ]
                      )
                      ||
                      !empty(
                          $verification[
                              'email'
                          ]
                      )
                  ): ?>

                    <br>

                    <span class="admin-muted">
                      Verified by:
                    </span>

                    <?= e(
                        person_label(
                            $verification
                        )
                    ) ?>

                  <?php endif; ?>


                  <?php if (
                      !empty(
                          $verification[
                              'notes'
                          ]
                      )
                  ): ?>

                    <p>
                      <?= nl2br(
                          e(
                              $verification[
                                  'notes'
                              ]
                          )
                      ) ?>
                    </p>

                  <?php endif; ?>


                </div>

              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              No verification history.
            </p>

          </div>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           PROBLEM REPORTS
           =============================================== -->

      <section
        class="admin-panel"
        id="problem-reports"
      >

        <div class="admin-panel-header">

          <div>

            <h2>
              Problem Reports
            </h2>

            <p>
              Reports submitted by Llama Scout members
              about this place.
            </p>

          </div>


          <?php if (
              $openReports > 0
          ): ?>

            <span
              class="
                admin-badge
                admin-badge--warning
              "
            >

              <?= $openReports ?>

              Open

            </span>

          <?php endif; ?>

        </div>


        <?php if (
            $reports
        ): ?>


          <?php foreach (
              $reports as $report
          ): ?>


            <?php

            $reportId =
                (int)
                $report[
                    'id'
                ];


            $photos =
                $reportImages[
                    $reportId
                ]
                ?? [];


            $adminNotes =
                has_column(
                    $db,
                    'place_reports',
                    'admin_notes'
                )
                    ? (
                        $report[
                            'admin_notes'
                        ]
                        ?? ''
                    )
                    : '';


            $reviewedAt =
                has_column(
                    $db,
                    'place_reports',
                    'reviewed_at'
                )
                    ? (
                        $report[
                            'reviewed_at'
                        ]
                        ?? null
                    )
                    : null;


            $reportBadge =
                match (
                    $report[
                        'status'
                    ]
                ) {

                    'open' =>
                        'admin-badge--danger',

                    'investigating' =>
                        'admin-badge--warning',

                    'resolved' =>
                        'admin-badge--success',

                    default =>
                        'admin-badge--muted',

                };

            ?>


            <article
              class="admin-card"
              style="margin-bottom:16px;"
            >


              <div class="admin-panel-header">

                <div>

                  <h3>

                    <?= e(
                        human_label(
                            $report[
                                'problem_type'
                            ]
                        )
                    ) ?>

                  </h3>

                  <p>

                    Report
                    #<?= $reportId ?>

                  </p>

                </div>


                <span
                  class="
                    admin-badge
                    <?= e(
                        $reportBadge
                    ) ?>
                  "
                >

                  <?= e(
                      human_label(
                          $report[
                              'status'
                          ]
                      )
                  ) ?>

                </span>

              </div>


              <?php if (
                  !empty(
                      $report[
                          'details'
                      ]
                  )
              ): ?>

                <p>
                  <?= nl2br(
                      e(
                          $report[
                              'details'
                          ]
                      )
                  ) ?>
                </p>

              <?php endif; ?>


              <?php if (
                  $photos
              ): ?>

                <div
                  class="admin-grid"
                  style="
                    margin-top:16px;
                    margin-bottom:16px;
                  "
                >


                  <?php foreach (
                      $photos as $photo
                  ): ?>


                    <?php

                    $photoUrl =
                        '/report-image.php?id='
                        .
                        rawurlencode(
                            (string)
                            $photo[
                                'id'
                            ]
                        );


                    $extension =
                        strtolower(
                            pathinfo(
                                $photo[
                                    'file_path'
                                ],
                                PATHINFO_EXTENSION
                            )
                        );


                    $browserPreviewable =
                        in_array(
                            $extension,
                            [
                                'jpg',
                                'jpeg',
                                'png',
                                'webp',
                                'avif',
                            ],
                            true
                        );

                    ?>


                    <a
                      class="admin-card"

                      href="<?= e(
                          $photoUrl
                      ) ?>"

                      target="_blank"
                      rel="noopener noreferrer"
                    >


                      <?php if (
                          $browserPreviewable
                      ): ?>

                        <img
                          src="<?= e(
                              $photoUrl
                          ) ?>"

                          alt="Problem report evidence"

                          style="
                            display:block;
                            width:100%;
                            aspect-ratio:4/3;
                            object-fit:cover;
                            border-radius:8px;
                            margin-bottom:10px;
                          "
                        >

                      <?php else: ?>

                        <div
                          style="
                            padding:24px;
                            text-align:center;
                          "
                        >

                          <strong>
                            Attached File
                          </strong>

                          <p>
                            <?= e(
                                strtoupper(
                                    $extension
                                )
                            ) ?>
                          </p>

                        </div>

                      <?php endif; ?>


                      <p>
                        <?= e(
                            $photo[
                                'original_name'
                            ]
                            ?: 'Open attachment'
                        ) ?>
                      </p>

                    </a>


                  <?php endforeach; ?>


                </div>

              <?php endif; ?>


              <div class="admin-detail-list">


                <div class="admin-detail-row">

                  <div class="admin-detail-label">
                    Reporter
                  </div>

                  <div class="admin-detail-value">

                    <?= e(
                        person_label(
                            $report,
                            'reporter_'
                        )
                    ) ?>

                  </div>

                </div>


                <div class="admin-detail-row">

                  <div class="admin-detail-label">
                    Reported
                  </div>

                  <div class="admin-detail-value">

                    <?= e(
                        format_date(
                            $report[
                                'created_at'
                            ],
                            true
                        )
                    ) ?>

                  </div>

                </div>


                <?php if (
                    !empty(
                        $report[
                            'reviewer_display_name'
                        ]
                    )
                    ||
                    !empty(
                        $report[
                            'reviewer_username'
                        ]
                    )
                    ||
                    !empty(
                        $report[
                            'reviewer_email'
                        ]
                    )
                ): ?>

                  <div class="admin-detail-row">

                    <div class="admin-detail-label">
                      Last Reviewed
                    </div>

                    <div class="admin-detail-value">

                      <?= e(
                          person_label(
                              $report,
                              'reviewer_'
                          )
                      ) ?>


                      <?php if (
                          $reviewedAt
                      ): ?>

                        <br>

                        <?= e(
                            format_date(
                                $reviewedAt,
                                true
                            )
                        ) ?>

                      <?php endif; ?>

                    </div>

                  </div>

                <?php endif; ?>


              </div>


              <form
                method="post"
                class="admin-form"
                style="margin-top:20px;"
              >

                <input
                  type="hidden"
                  name="action"
                  value="update_report"
                >

                <input
                  type="hidden"
                  name="place_id"
                  value="<?= $placeId ?>"
                >

                <input
                  type="hidden"
                  name="report_id"
                  value="<?= $reportId ?>"
                >

                <input
                  type="hidden"
                  name="csrf_token"
                  value="<?= e(
                      $csrfToken
                  ) ?>"
                >


                <div class="admin-form-grid">


                  <div class="admin-field">

                    <label>
                      Report Status
                    </label>

                    <select
                      name="report_status"
                    >

                      <?php foreach (
                          [
                              'open' =>
                                  'Open',

                              'investigating' =>
                                  'Investigating',

                              'resolved' =>
                                  'Resolved',

                              'dismissed' =>
                                  'Dismissed',

                          ] as
                          $value =>
                          $label
                      ): ?>

                        <option
                          value="<?= e(
                              $value
                          ) ?>"
                          <?= $report[
                              'status'
                          ] === $value
                              ? 'selected'
                              : ''
                          ?>
                        >

                          <?= e(
                              $label
                          ) ?>

                        </option>

                      <?php endforeach; ?>

                    </select>

                  </div>


                  <div class="admin-field">

                    <label>
                      Admin Notes
                    </label>

                    <textarea
                      name="admin_notes"
                      placeholder="What did you verify or decide?"
                    ><?= e(
                        $adminNotes
                    ) ?></textarea>

                  </div>


                </div>


                <div class="admin-form-actions">

                  <button
                    type="submit"
                    class="admin-button"
                  >

                    <i
                      class="fa-solid fa-floppy-disk"
                      aria-hidden="true"
                    ></i>

                    Save Report

                  </button>

                </div>

              </form>


            </article>


          <?php endforeach; ?>


        <?php else: ?>

          <div class="admin-empty">

            <p>
              No problems have been reported.
            </p>

          </div>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           STATUS HISTORY
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Status History
          </h2>

        </div>


        <?php if (
            $statusHistory
        ): ?>

          <div class="admin-detail-list">


            <?php foreach (
                $statusHistory as
                $history
            ): ?>


              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      status_label(
                          $history[
                              'new_status'
                          ]
                      )
                  ) ?>

                </div>


                <div class="admin-detail-value">


                  <?php if (
                      !empty(
                          $history[
                              'old_status'
                          ]
                      )
                  ): ?>

                    <strong>

                      <?= e(
                          status_label(
                              $history[
                                  'old_status'
                              ]
                          )
                      ) ?>

                      &rarr;

                      <?= e(
                          status_label(
                              $history[
                                  'new_status'
                              ]
                          )
                      ) ?>

                    </strong>

                    <br>

                  <?php endif; ?>


                  <?= e(
                      format_date(
                          $history[
                              'changed_at'
                          ],
                          true
                      )
                  ) ?>


                  <?php if (
                      !empty(
                          $history[
                              'display_name'
                          ]
                      )
                      ||
                      !empty(
                          $history[
                              'username'
                          ]
                      )
                  ): ?>

                    <br>

                    <span class="admin-muted">
                      Changed by:
                    </span>

                    <?= e(
                        $history[
                            'display_name'
                        ]
                        ?: $history[
                            'username'
                        ]
                    ) ?>

                  <?php endif; ?>


                  <?php if (
                      !empty(
                          $history[
                              'reason'
                          ]
                      )
                  ): ?>

                    <p>

                      <?= nl2br(
                          e(
                              $history[
                                  'reason'
                              ]
                          )
                      ) ?>

                    </p>

                  <?php endif; ?>


                </div>

              </div>


            <?php endforeach; ?>


          </div>


        <?php else: ?>

          <div class="admin-empty">

            <p>
              No status history.
            </p>

          </div>

        <?php endif; ?>


      </section>


    </div>


    <!-- ===================================================
         SIDEBAR
         =================================================== -->

    <aside class="admin-detail-sidebar">


      <?php if (
          $openReports > 0
      ): ?>

        <div
          class="
            admin-notice
            admin-notice--warning
          "
        >

          <p>

            <strong>

              <?= $openReports ?>

              open

              <?= $openReports === 1
                  ? 'report'
                  : 'reports'
              ?>

            </strong>

            <br>

            This place has a problem report
            that may need investigation.

          </p>

        </div>

      <?php endif; ?>


      <!-- ===============================================
           VERIFY PLACE
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Verify Place
            </h2>

            <p>
              Record a new verification event.
            </p>

          </div>

        </div>


        <form
          method="post"
          class="admin-form"
        >

          <input
            type="hidden"
            name="action"
            value="verify_place"
          >

          <input
            type="hidden"
            name="place_id"
            value="<?= $placeId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >


          <div class="admin-field">

            <label for="verification_type">
              Verification Type
            </label>

            <select
              id="verification_type"
              name="verification_type"
              required
            >

              <?php foreach (
                  $verificationTypes as
                  $verificationType
              ): ?>

                <option
                  value="<?= e(
                      $verificationType
                  ) ?>"
                >

                  <?= e(
                      human_label(
                          $verificationType
                      )
                  ) ?>

                </option>

              <?php endforeach; ?>

            </select>

          </div>


          <div class="admin-field">

            <label for="visited_at">
              Date Visited
            </label>

            <input
              id="visited_at"
              name="visited_at"
              type="date"
              max="<?= e(
                  date(
                      'Y-m-d'
                  )
              ) ?>"
            >

            <p class="admin-field-help">

              Leave blank for remote
              or records-based verification.

            </p>

          </div>


          <div class="admin-field">

            <label for="verification_source">
              Source
            </label>

            <input
              id="verification_source"
              name="verification_source"
              type="text"
              maxlength="255"
              placeholder="On-site visit, USFS notice, member report..."
            >

          </div>


          <div class="admin-field">

            <label for="verification_notes">
              Verification Notes
            </label>

            <textarea
              id="verification_notes"
              name="verification_notes"
              maxlength="3000"
              placeholder="What did you check, confirm, or change?"
            ></textarea>

          </div>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="admin-button"
            >

              <i
                class="fa-solid fa-circle-check"
                aria-hidden="true"
              ></i>

              Record Verification

            </button>

          </div>

        </form>

      </section>


      <!-- ===============================================
           PLACE STATUS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Place Status
            </h2>

            <p>
              Control public availability and prominence.
            </p>

          </div>

        </div>


        <form
          method="post"
          class="admin-form"
        >

          <input
            type="hidden"
            name="action"
            value="place_status"
          >

          <input
            type="hidden"
            name="place_id"
            value="<?= $placeId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >


          <div class="admin-field">

            <label for="status">
              Status
            </label>

            <select
              id="status"
              name="status"
            >

              <?php foreach (
                  [
                      'draft',
                      'active',
                      'featured',
                      'unlisted',
                      'removed',
                      'archived',
                  ] as $status
              ): ?>

                <option
                  value="<?= e(
                      $status
                  ) ?>"
                  <?= $place[
                      'status'
                  ] === $status
                      ? 'selected'
                      : ''
                  ?>
                >

                  <?= e(
                      status_label(
                          $status
                      )
                  ) ?>

                </option>

              <?php endforeach; ?>

            </select>

          </div>


          <div class="admin-field">

            <label for="status_reason">
              Reason
            </label>

            <textarea
              id="status_reason"
              name="status_reason"
              placeholder="Why is this status changing?"
            ></textarea>

            <p class="admin-field-help">

              A reason is required for
              Unlisted, Removed, and Archived.

            </p>

          </div>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="admin-button"
            >

              Update Status

            </button>

          </div>

        </form>

      </section>


      <!-- ===============================================
           CURRENT STATUS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Current Status Details
          </h2>

        </div>


        <?php

        render_rows([

            'Status' =>
                status_label(
                    $place[
                        'status'
                    ]
                ),

            'Reason' =>
                plain_value(
                    $place[
                        'status_reason'
                    ]
                    ?? null
                ),

            'Changed' =>
                format_date(
                    $place[
                        'status_changed_at'
                    ]
                    ?? null,
                    true
                ),

        ]);

        ?>

      </section>


      <!-- ===============================================
           QUICK LINKS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <h2>
            Quick Links
          </h2>

        </div>


        <div class="admin-form">

          <a
            class="
              admin-button
              admin-button--secondary
            "

            href="https://llamascout.com/place.php?place=<?= rawurlencode(
                (string)
                $place[
                    'slug'
                ]
            ) ?>"

            target="_blank"
            rel="noopener noreferrer"
          >

            View Current Public Page

          </a>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/public-preview.php?id=<?= $placeId ?>"
          >

            <i
              class="fa-solid fa-eye"
              aria-hidden="true"
            ></i>

            Public Preview

          </a>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/places.php"
          >

            All Places

          </a>


          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/submissions.php"
          >

            Community Submissions

          </a>


          <?php if (
              $openReports > 0
          ): ?>

            <a
              class="
                admin-button
                admin-button--warning
              "
              href="#problem-reports"
            >

              Review Problem Reports

            </a>

          <?php endif; ?>


        </div>

      </section>


    </aside>


  </div>


  <!-- =====================================================
       FOOT ACTIONS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/places.php">
      All Places
    </a>

    <a
      href="https://llamascout.com/place.php?place=<?= rawurlencode(
          (string)
          $place[
              'slug'
          ]
      ) ?>"
      target="_blank"
      rel="noopener noreferrer"
    >
      Public Page
    </a>

    <a href="/">
      Basecamp
    </a>

  </div>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
