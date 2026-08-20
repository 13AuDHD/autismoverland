<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   DAILY SCOUT MAINTENANCE
   =========================================================

   This file is designed to be included by the application.

   It runs Scout renewal maintenance at most once per day.

   It does NOT depend on cron.

   The first qualifying application request of the day runs
   the maintenance check. Later requests that day do nothing.

   ========================================================= */


/* =========================================================
   SETTINGS
   ========================================================= */

const LLAMA_SCOUT_REPORTS_REQUIRED =
    3;


/*
 * Maintenance frequency.
 *
 * 86400 seconds = 24 hours.
 */

const LLAMA_SCOUT_MAINTENANCE_INTERVAL =
    86400;


/* =========================================================
   ENSURE MAINTENANCE TABLE EXISTS
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
   CHECK WHETHER MAINTENANCE IS DUE
   ========================================================= */

function llama_scout_maintenance_is_due(
    PDO $db
): bool {

    llama_ensure_maintenance_table(
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
   RUN SCOUT RENEWAL MAINTENANCE
   ========================================================= */

function llama_run_scout_renewal_maintenance(
    PDO $db
): array {

    $summary = [

        'processed' => 0,
        'renewed' => 0,
        'inactive' => 0,
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
     * Get all active Scouts whose current Scout year
     * has already ended.
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


            $yearEndTimestamp =
                strtotime(
                    $activeThrough
                );


            if (
                $yearEndTimestamp === false
            ) {

                throw new RuntimeException(
                    'Scout active_through date was invalid.'
                );

            }


            /*
             * Another request might have renewed this Scout
             * after the original expired list was loaded.
             */

            if (
                $yearEndTimestamp
                >
                time()
            ) {

                $db->rollBack();

                continue;

            }


            /* =============================================
               DETERMINE SCOUT YEAR
               ============================================= */

            $yearStartTimestamp =
                strtotime(
                    '-1 year',
                    $yearEndTimestamp
                );


            $scoutStartedAt =
                trim(
                    (string) (
                        $currentScout[
                            'scout_started_at'
                        ]
                        ?? ''
                    )
                );


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
                    $yearEndTimestamp
                );


            /* =============================================
               COUNT ACCEPTED SCOUT REPORTS
               ============================================= */

            $activityStmt =
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


            $activityStmt->execute([
                $scoutProfileId,
                $userId,
                $yearStart,
                $yearEnd
            ]);


            $acceptedReports =
                (int)
                $activityStmt
                    ->fetchColumn();


            /* =============================================
               REQUIREMENT MET
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


                /*
                 * If this Scout is currently using the
                 * complimentary membership record, extend
                 * that membership to the same Scout date.
                 *
                 * Paid Stripe membership records are left
                 * alone.
                 */

                $membershipStmt =
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


                $membershipStmt->execute([
                    $userId,
                    $scoutProfileId
                ]);


                $db->commit();


                $summary[
                    'renewed'
                ]++;


                continue;

            }


            /* =============================================
               REQUIREMENT NOT MET
               ============================================= */

            $inactiveStmt =
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


            $inactiveStmt->execute([
                $scoutProfileId,
                $userId
            ]);


            if (
                $inactiveStmt->rowCount()
                !==
                1
            ) {

                throw new RuntimeException(
                    'Scout deactivation failed.'
                );

            }


            /* =============================================
               REMOVE SCOUT ROLES
               ============================================= */

            $removeRolesStmt =
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


            $removeRolesStmt->execute([
                $userId
            ]);


            /* =============================================
               END COMPLIMENTARY MEMBERSHIP

               Only touch complimentary membership.

               Never alter an unrelated paid Stripe
               subscription here.
               ============================================= */

            $membershipStmt =
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


            $membershipStmt->execute([
                $userId
            ]);


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
     * We mark the daily maintenance run after processing the
     * full batch.
     *
     * Individual Scout failures were logged and do not stop
     * other Scouts from being processed.
     */

    llama_mark_scout_maintenance_run(
        $db
    );


    return
        $summary;

}
