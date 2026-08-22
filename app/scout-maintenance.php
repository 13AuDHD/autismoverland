<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   DAILY SCOUT MAINTENANCE
   =========================================================

   Runs Scout renewal / expiration maintenance at most once
   per day.

   No cron is required. The first qualifying application
   request after the maintenance interval runs the check.

   Scout policy:

   - Standard Scout year:
       3 accepted Scout Reports required.

   - Requirement met:
       extend exactly one year.

   - Requirement not met:
       Scout access ends.
       Scout / Master Scout roles are removed.
       Complimentary membership ends.
       Lifetime Scout points remain permanently recorded.

   - Admin / Owner may later grant a separate 30-day basic
     Scout extension.

   - A 30-day extension requires 3 newly accepted Scout
     Reports during that exact extension window.

   - Successful extension:
       member returns as BASIC Scout for one full year.
       Master Scout is never automatically restored.
       Existing lifetime points remain intact.

   - Failed extension:
       member returns to free status.
       Existing lifetime points remain intact.

   Lifetime points represent historical contribution to
   Llama Scout. They do not grant or preserve active Scout
   status by themselves.

   ========================================================= */


/* =========================================================
   SETTINGS
   ========================================================= */

const LLAMA_SCOUT_REPORTS_REQUIRED =
    3;


const LLAMA_SCOUT_MAINTENANCE_INTERVAL =
    86400;


/* =========================================================
   MAINTENANCE TABLE
   ========================================================= */

function llama_ensure_maintenance_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS app_maintenance
        (
            maintenance_key
                VARCHAR(100)
                NOT NULL,

            last_run_at
                DATETIME
                NULL,

            updated_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY
                (maintenance_key)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );

}


/* =========================================================
   SCOUT EXTENSIONS TABLE

   A reinstatement extension is intentionally separate from
   scout_profiles.

   This preserves the original Scout start date and gives us
   an exact 30-day qualification window.
   ========================================================= */

function llama_ensure_scout_extensions_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS scout_extensions
        (
            id
                BIGINT UNSIGNED
                NOT NULL
                AUTO_INCREMENT,

            scout_profile_id
                BIGINT UNSIGNED
                NOT NULL,

            user_id
                BIGINT UNSIGNED
                NOT NULL,

            granted_by
                BIGINT UNSIGNED
                NULL,

            started_at
                DATETIME
                NOT NULL,

            ends_at
                DATETIME
                NOT NULL,

            status
                ENUM(
                    \'active\',
                    \'completed\',
                    \'failed\',
                    \'canceled\'
                )
                NOT NULL
                DEFAULT \'active\',

            accepted_reports
                INT UNSIGNED
                NOT NULL
                DEFAULT 0,

            resolved_at
                DATETIME
                NULL,

            created_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            updated_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY
                (id),

            KEY idx_scout_extension_profile
                (
                    scout_profile_id,
                    status
                ),

            KEY idx_scout_extension_user
                (
                    user_id,
                    status
                ),

            KEY idx_scout_extension_end
                (
                    status,
                    ends_at
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );

}


/* =========================================================
   CHECK WHETHER MAINTENANCE IS DUE
   ========================================================= */

function llama_scout_maintenance_is_due(
    PDO $db
): bool {

    llama_ensure_maintenance_table(
        $db
    );


    llama_ensure_scout_extensions_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                last_run_at

            FROM app_maintenance

            WHERE maintenance_key =
                \'scout_renewals\'

            LIMIT 1
            '
        );


    $stmt->execute();


    $lastRun =
        $stmt->fetchColumn();


    if (
        !$lastRun
    ) {

        return true;
    }


    $lastRunTimestamp =
        strtotime(
            (string)
            $lastRun
        );


    if (
        $lastRunTimestamp === false
    ) {

        return true;
    }


    return
        (
            time()
            -
            $lastRunTimestamp
        )
        >=
        LLAMA_SCOUT_MAINTENANCE_INTERVAL;

}


/* =========================================================
   MARK MAINTENANCE COMPLETE
   ========================================================= */

function llama_mark_scout_maintenance_run(
    PDO $db
): void {

    $stmt =
        $db->prepare(
            '
            INSERT INTO app_maintenance
            (
                maintenance_key,
                last_run_at
            )

            VALUES
            (
                \'scout_renewals\',
                CURRENT_TIMESTAMP
            )

            ON DUPLICATE KEY UPDATE

                last_run_at =
                    CURRENT_TIMESTAMP
            '
        );


    $stmt->execute();

}


/* =========================================================
   COUNT ACCEPTED SCOUT REPORTS IN A FIXED PERIOD
   ========================================================= */

function llama_count_scout_reports(
    PDO $db,
    int $scoutProfileId,
    int $userId,
    string $periodStart,
    string $periodEnd
): int {

    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM scout_activity

            WHERE scout_profile_id = ?

              AND user_id = ?

              AND activity_type =
                  \'place_approved\'

              AND occurred_at >= ?

              AND occurred_at < ?
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId,
        $periodStart,
        $periodEnd
    ]);


    return
        (int)
        $stmt->fetchColumn();

}


/* =========================================================
   REMOVE SCOUT / MASTER SCOUT ROLES
   ========================================================= */

function llama_remove_scout_roles(
    PDO $db,
    int $userId
): void {

    $stmt =
        $db->prepare(
            '
            DELETE ur

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id = ur.role_id

            WHERE ur.user_id = ?

              AND r.slug IN
              (
                  \'scout\',
                  \'master-scout\',
                  \'master_scout\'
              )
            '
        );


    $stmt->execute([
        $userId
    ]);

}


/* =========================================================
   ENSURE BASIC SCOUT ROLE

   Used after a successful 30-day reinstatement.

   Master Scout is deliberately NOT restored.
   ========================================================= */

function llama_grant_basic_scout_role(
    PDO $db,
    int $userId
): void {

    llama_remove_scout_roles(
        $db,
        $userId
    );


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO user_roles
            (
                user_id,
                role_id
            )

            SELECT
                ?,
                r.id

            FROM roles r

            WHERE r.slug =
                \'scout\'

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $check =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id = ur.role_id

            WHERE ur.user_id = ?

              AND r.slug =
                  \'scout\'
            '
        );


    $check->execute([
        $userId
    ]);


    if (
        (int)
        $check->fetchColumn()
        < 1
    ) {

        throw new RuntimeException(
            'The Scout role could not be granted.'
        );

    }

}


/* =========================================================
   END COMPLIMENTARY MEMBERSHIP

   Only Scout-created complimentary membership is affected.

   A legitimate separate paid Stripe membership is never
   silently canceled by Scout maintenance.
   ========================================================= */

function llama_end_scout_complimentary_membership(
    PDO $db,
    int $userId
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE users

            SET
                membership_status =
                    \'canceled\',

                membership_ends_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND membership_status =
                  \'complimentary\'
            '
        );


    $stmt->execute([
        $userId
    ]);

}


/* =========================================================
   SYNC COMPLIMENTARY MEMBERSHIP TO SCOUT DATE
   ========================================================= */

function llama_sync_scout_membership_end(
    PDO $db,
    int $userId,
    int $scoutProfileId
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE users u

            INNER JOIN scout_profiles sp
              ON sp.user_id = u.id

            SET
                u.membership_ends_at =
                    sp.active_through

            WHERE u.id = ?

              AND sp.id = ?

              AND u.membership_status =
                  \'complimentary\'
            '
        );


    $stmt->execute([
        $userId,
        $scoutProfileId
    ]);

}


/* =========================================================
   EXPIRE SCOUT ACCESS

   Used for:
   - failed normal Scout year
   - failed 30-day extension

   This removes active Scout status and rank.

   It does NOT delete:
   - the user's reports
   - Scout activity/history
   - lifetime points

   Lifetime points remain part of the user's permanent
   contribution history but do not preserve Scout status.
   ========================================================= */

function llama_expire_scout_access(
    PDO $db,
    int $scoutProfileId,
    int $userId
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE scout_profiles

            SET
                status =
                    \'inactive\',

                inactive_at =
                    CURRENT_TIMESTAMP,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND user_id = ?

              AND status =
                  \'active\'
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'Scout deactivation failed.'
        );

    }


    llama_remove_scout_roles(
        $db,
        $userId
    );


    llama_end_scout_complimentary_membership(
        $db,
        $userId
    );

}


/* =========================================================
   ACTIVE REINSTATEMENT EXTENSION
   ========================================================= */

function llama_active_scout_extension(
    PDO $db,
    int $scoutProfileId,
    int $userId
): ?array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                scout_profile_id,
                user_id,
                granted_by,
                started_at,
                ends_at,
                status,
                accepted_reports

            FROM scout_extensions

            WHERE scout_profile_id = ?

              AND user_id = ?

              AND status =
                  \'active\'

            ORDER BY
                id DESC

            LIMIT 1

            FOR UPDATE
            '
        );


    $stmt->execute([
        $scoutProfileId,
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
   COMPLETE 30-DAY EXTENSION

   Successful reinstatement always returns the person as
   BASIC Scout.

   Their former Master Scout rank is not restored.

   A fresh one-year Scout period begins at the END of the
   extension period.

   Lifetime points earned before and during inactivity remain
   intact.
   ========================================================= */

function llama_complete_scout_extension(
    PDO $db,
    array $extension,
    int $acceptedReports
): void {

    $extensionId =
        (int)
        $extension[
            'id'
        ];


    $scoutProfileId =
        (int)
        $extension[
            'scout_profile_id'
        ];


    $userId =
        (int)
        $extension[
            'user_id'
        ];


    $extensionEnd =
        trim(
            (string)
            $extension[
                'ends_at'
            ]
        );


    if (
        $extensionEnd === ''
        ||
        strtotime(
            $extensionEnd
        ) === false
    ) {

        throw new RuntimeException(
            'The Scout extension end date is invalid.'
        );

    }


    /*
     * Convert the temporary extension into a fresh,
     * full one-year basic Scout period.
     */

    $profileStmt =
        $db->prepare(
            '
            UPDATE scout_profiles

            SET
                status =
                    \'active\',

                active_through =
                    DATE_ADD(
                        ?,
                        INTERVAL 1 YEAR
                    ),

                inactive_at =
                    NULL,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND user_id = ?

              AND status =
                  \'active\'
            '
        );


    $profileStmt->execute([
        $extensionEnd,
        $scoutProfileId,
        $userId
    ]);


    if (
        $profileStmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The Scout extension could not be converted into annual Scout access.'
        );

    }


    $extensionStmt =
        $db->prepare(
            '
            UPDATE scout_extensions

            SET
                status =
                    \'completed\',

                accepted_reports = ?,

                resolved_at =
                    CURRENT_TIMESTAMP,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND status =
                  \'active\'
            '
        );


    $extensionStmt->execute([
        $acceptedReports,
        $extensionId
    ]);


    if (
        $extensionStmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The Scout extension could not be marked complete.'
        );

    }


    llama_grant_basic_scout_role(
        $db,
        $userId
    );


    llama_sync_scout_membership_end(
        $db,
        $userId,
        $scoutProfileId
    );

}


/* =========================================================
   FAIL 30-DAY EXTENSION
   ========================================================= */

function llama_fail_scout_extension(
    PDO $db,
    array $extension,
    int $acceptedReports
): void {

    $extensionId =
        (int)
        $extension[
            'id'
        ];


    $scoutProfileId =
        (int)
        $extension[
            'scout_profile_id'
        ];


    $userId =
        (int)
        $extension[
            'user_id'
        ];


    $extensionStmt =
        $db->prepare(
            '
            UPDATE scout_extensions

            SET
                status =
                    \'failed\',

                accepted_reports = ?,

                resolved_at =
                    CURRENT_TIMESTAMP,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND status =
                  \'active\'
            '
        );


    $extensionStmt->execute([
        $acceptedReports,
        $extensionId
    ]);


    if (
        $extensionStmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The expired Scout extension could not be closed.'
        );

    }


    llama_expire_scout_access(
        $db,
        $scoutProfileId,
        $userId
    );

}


/* =========================================================
   RUN SCOUT RENEWAL MAINTENANCE
   ========================================================= */

function llama_run_scout_renewal_maintenance(
    PDO $db
): array {

    $summary = [

        'processed' => 0,

        'renewed' => 0,

        'inactive' => 0,

        'extensions_completed' => 0,

        'extensions_failed' => 0,

        'errors' => 0,

    ];


    if (
        !llama_scout_maintenance_is_due(
            $db
        )
    ) {

        return
            $summary;

    }


    /*
     * Load every active Scout whose current access period
     * has expired.

     * active_through is used for both:
     *
     * - normal one-year Scout periods
     * - temporary 30-day reinstatement periods
     *
     * scout_extensions tells us which kind of period it is.
     */

    $stmt =
        $db->query(
            '
            SELECT
                id,
                user_id,
                scout_started_at,
                active_through

            FROM scout_profiles

            WHERE status =
                \'active\'

              AND active_through
                  IS NOT NULL

              AND active_through <=
                  CURRENT_TIMESTAMP

            ORDER BY
                active_through ASC,
                id ASC
            '
        );


    $expiredScouts =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    foreach (
        $expiredScouts
        as
        $scout
    ) {

        $summary[
            'processed'
        ]++;


        $scoutProfileId =
            (int)
            $scout[
                'id'
            ];


        $userId =
            (int)
            $scout[
                'user_id'
            ];


        try {

            $db->beginTransaction();


            /* =============================================
               LOCK SCOUT PROFILE
               ============================================= */

            $lockStmt =
                $db->prepare(
                    '
                    SELECT
                        id,
                        user_id,
                        status,
                        scout_started_at,
                        active_through

                    FROM scout_profiles

                    WHERE id = ?

                      AND user_id = ?

                    LIMIT 1

                    FOR UPDATE
                    '
                );


            $lockStmt->execute([
                $scoutProfileId,
                $userId
            ]);


            $currentScout =
                $lockStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (
                !$currentScout
            ) {

                throw new RuntimeException(
                    'Scout profile was not found.'
                );

            }


            if (
                (
                    $currentScout[
                        'status'
                    ]
                    ?? ''
                )
                !==
                'active'
            ) {

                $db->rollBack();

                continue;

            }


            $activeThrough =
                trim(
                    (string) (
                        $currentScout[
                            'active_through'
                        ]
                        ?? ''
                    )
                );


            if (
                $activeThrough === ''
            ) {

                $db->rollBack();

                continue;

            }


            $periodEndTimestamp =
                strtotime(
                    $activeThrough
                );


            if (
                $periodEndTimestamp === false
            ) {

                throw new RuntimeException(
                    'Scout active_through date was invalid.'
                );

            }


            /*
             * Another request may have renewed the Scout
             * after the original expired list was selected.
             */

            if (
                $periodEndTimestamp
                >
                time()
            ) {

                $db->rollBack();

                continue;

            }


            /* =============================================
               IS THIS A 30-DAY REINSTATEMENT?
               ============================================= */

            $extension =
                llama_active_scout_extension(
                    $db,
                    $scoutProfileId,
                    $userId
                );


            if (
                $extension
            ) {

                $extensionStart =
                    trim(
                        (string) (
                            $extension[
                                'started_at'
                            ]
                            ?? ''
                        )
                    );


                $extensionEnd =
                    trim(
                        (string) (
                            $extension[
                                'ends_at'
                            ]
                            ?? ''
                        )
                    );


                if (
                    $extensionStart === ''
                    ||
                    $extensionEnd === ''
                    ||
                    strtotime(
                        $extensionStart
                    ) === false
                    ||
                    strtotime(
                        $extensionEnd
                    ) === false
                ) {

                    throw new RuntimeException(
                        'Scout extension dates were invalid.'
                    );

                }


                /*
                 * If the extension record somehow ends later
                 * than the profile access date, repair the
                 * profile instead of prematurely expiring it.
                 */

                if (
                    strtotime(
                        $extensionEnd
                    )
                    >
                    time()
                ) {

                    $repairStmt =
                        $db->prepare(
                            '
                            UPDATE scout_profiles

                            SET
                                active_through = ?,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id = ?

                              AND user_id = ?

                              AND status =
                                  \'active\'
                            '
                        );


                    $repairStmt->execute([
                        $extensionEnd,
                        $scoutProfileId,
                        $userId
                    ]);


                    $db->commit();

                    continue;

                }


                $acceptedReports =
                    llama_count_scout_reports(
                        $db,
                        $scoutProfileId,
                        $userId,
                        $extensionStart,
                        $extensionEnd
                    );


                if (
                    $acceptedReports
                    >=
                    LLAMA_SCOUT_REPORTS_REQUIRED
                ) {

                    llama_complete_scout_extension(
                        $db,
                        $extension,
                        $acceptedReports
                    );


                    $db->commit();


                    $summary[
                        'extensions_completed'
                    ]++;


                    continue;

                }


                llama_fail_scout_extension(
                    $db,
                    $extension,
                    $acceptedReports
                );


                $db->commit();


                $summary[
                    'extensions_failed'
                ]++;


                $summary[
                    'inactive'
                ]++;


                continue;

            }


            /* =============================================
               NORMAL ONE-YEAR SCOUT PERIOD
               ============================================= */

            $yearStartTimestamp =
                strtotime(
                    '-1 year',
                    $periodEndTimestamp
                );


            if (
                $yearStartTimestamp === false
            ) {

                throw new RuntimeException(
                    'Scout year start date could not be determined.'
                );

            }


            $scoutStartedAt =
                trim(
                    (string) (
                        $currentScout[
                            'scout_started_at'
                        ]
                        ?? ''
                    )
                );


            /*
             * First Scout year can never begin before the
             * person actually became a Scout.
             */

            if (
                $scoutStartedAt !== ''
            ) {

                $scoutStartedTimestamp =
                    strtotime(
                        $scoutStartedAt
                    );


                if (
                    $scoutStartedTimestamp !== false
                    &&
                    $scoutStartedTimestamp
                    >
                    $yearStartTimestamp
                ) {

                    $yearStartTimestamp =
                        $scoutStartedTimestamp;

                }

            }


            $yearStart =
                date(
                    'Y-m-d H:i:s',
                    $yearStartTimestamp
                );


            $yearEnd =
                date(
                    'Y-m-d H:i:s',
                    $periodEndTimestamp
                );


            $acceptedReports =
                llama_count_scout_reports(
                    $db,
                    $scoutProfileId,
                    $userId,
                    $yearStart,
                    $yearEnd
                );


            /* =============================================
               NORMAL YEAR REQUIREMENT MET
               ============================================= */

            if (
                $acceptedReports
                >=
                LLAMA_SCOUT_REPORTS_REQUIRED
            ) {

                /*
                 * Extend exactly one year from the existing
                 * Scout anniversary.
                 */

                $renewStmt =
                    $db->prepare(
                        '
                        UPDATE scout_profiles

                        SET
                            active_through =
                                DATE_ADD(
                                    active_through,
                                    INTERVAL 1 YEAR
                                ),

                            inactive_at =
                                NULL,

                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id = ?

                          AND user_id = ?

                          AND status =
                              \'active\'
                        '
                    );


                $renewStmt->execute([
                    $scoutProfileId,
                    $userId
                ]);


                if (
                    $renewStmt->rowCount()
                    !==
                    1
                ) {

                    throw new RuntimeException(
                        'Scout renewal failed.'
                    );

                }


                llama_sync_scout_membership_end(
                    $db,
                    $userId,
                    $scoutProfileId
                );


                $db->commit();


                $summary[
                    'renewed'
                ]++;


                continue;

            }


            /* =============================================
               NORMAL YEAR REQUIREMENT NOT MET

               Scout and Master Scout both fall completely
               back to free-member status.

               Lifetime points remain permanently recorded.
               ============================================= */

            llama_expire_scout_access(
                $db,
                $scoutProfileId,
                $userId
            );


            $db->commit();


            $summary[
                'inactive'
            ]++;


        } catch (
            Throwable $exception
        ) {

            if (
                $db->inTransaction()
            ) {

                $db->rollBack();

            }


            $summary[
                'errors'
            ]++;


            error_log(
                'Llama Scout maintenance error for Scout profile '
                .
                $scoutProfileId
                .
                ': '
                .
                $exception
                    ->getMessage()
            );

        }

    }


    /*
     * Mark the daily run after the full batch.

     * An individual Scout failure is logged but does not stop
     * the rest of the batch.
     */

    llama_mark_scout_maintenance_run(
        $db
    );


    return
        $summary;

}
