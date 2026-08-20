<?php

declare(strict_types=1);

/*
 * =========================================================
 * LLAMA SCOUT
 * SCOUT YEAR RENEWAL PROCESSOR
 * =========================================================
 *
 * This file is intended to run from the server command line
 * through a scheduled cron job.
 *
 * It is NOT a public web endpoint.
 *
 *
 * SCOUT RENEWAL RULE
 * ---------------------------------------------------------
 *
 * A newly approved Scout receives one year of complimentary
 * Scout access.
 *
 * During each fixed Scout year they must complete at least
 * 3 accepted Scout Reports.
 *
 * Example:
 *
 * Aug 20, 2026 -> Aug 20, 2027
 *
 * 0 reports  = does not renew
 * 1 report   = does not renew
 * 2 reports  = does not renew
 * 3 reports  = renews one year
 * 6 reports  = renews one year
 * 12 reports = renews one year
 *
 * Extra reports DO NOT stack additional membership years.
 *
 * When the Scout year expires:
 *
 * Requirement met:
 *     active_through += 1 year
 *
 * Requirement not met:
 *     Scout profile becomes inactive
 *     Scout access roles are removed
 *
 * =========================================================
 */


/* =========================================================
   CLI ONLY
   ========================================================= */

if (
    PHP_SAPI !== 'cli'
) {

    http_response_code(
        403
    );

    exit(
        'This process may only be run from the command line.'
    );

}


/* =========================================================
   DATABASE
   ========================================================= */

require_once
    dirname(__DIR__)
    . '/app/database.php';


$db =
    db();


/* =========================================================
   SETTINGS
   ========================================================= */

const SCOUT_REPORTS_REQUIRED =
    3;


/* =========================================================
   OUTPUT HELPER
   ========================================================= */

function renewal_log(
    string $message
): void {

    echo
        '['
        .
        date(
            'Y-m-d H:i:s'
        )
        .
        '] '
        .
        $message
        .
        PHP_EOL;

}


/* =========================================================
   LOAD EXPIRED ACTIVE SCOUTS

   Only Scouts whose active_through date has arrived are
   processed.

   A Scout whose year ends tomorrow is left completely alone
   until that date actually arrives.
   ========================================================= */

$stmt =
    $db->query(
        '
        SELECT
            sp.id,
            sp.user_id,
            sp.status,
            sp.scout_started_at,
            sp.active_through,

            u.email,
            u.username,
            u.display_name

        FROM scout_profiles sp

        INNER JOIN users u
          ON u.id = sp.user_id

        WHERE sp.status = \'active\'

          AND sp.active_through IS NOT NULL

          AND sp.active_through <=
              CURRENT_TIMESTAMP

        ORDER BY
            sp.active_through ASC,
            sp.id ASC
        '
    );


$expiredScouts =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


$totalFound =
    count(
        $expiredScouts
    );


renewal_log(
    'Scout renewal check started.'
);


renewal_log(
    'Expired active Scout profiles found: '
    .
    $totalFound
);


/* =========================================================
   NOTHING TO PROCESS
   ========================================================= */

if (
    $totalFound === 0
) {

    renewal_log(
        'Nothing to process.'
    );


    exit(
        0
    );

}


/* =========================================================
   RUN COUNTERS
   ========================================================= */

$renewedCount =
    0;


$inactiveCount =
    0;


$errorCount =
    0;


/* =========================================================
   PROCESS EACH SCOUT
   ========================================================= */

foreach (
    $expiredScouts
    as
    $scout
) {

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


    $displayName =
        trim(
            (string) (
                $scout[
                    'display_name'
                ]
                ?:
                $scout[
                    'username'
                ]
                ?:
                $scout[
                    'email'
                ]
                ?:
                (
                    'User #'
                    .
                    $userId
                )
            )
        );


    try {

        $db->beginTransaction();


        /* =================================================
           LOCK CURRENT SCOUT PROFILE

           Another process cannot renew the same Scout at the
           same time while this row is locked.
           ================================================= */

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
                'Scout profile disappeared during processing.'
            );

        }


        /*
         * The profile may have been changed after the
         * original expired-Scout list was loaded.
         */

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


            renewal_log(
                $displayName
                .
                ': skipped because Scout status changed.'
            );


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


            renewal_log(
                $displayName
                .
                ': skipped because active_through is missing.'
            );


            continue;

        }


        $activeThroughTimestamp =
            strtotime(
                $activeThrough
            );


        if (
            $activeThroughTimestamp === false
        ) {

            throw new RuntimeException(
                'Invalid active_through date.'
            );

        }


        /*
         * If another process already extended the Scout year
         * after the initial list was loaded, do nothing.
         */

        if (
            $activeThroughTimestamp
            >
            time()
        ) {

            $db->rollBack();


            renewal_log(
                $displayName
                .
                ': already renewed by another process.'
            );


            continue;

        }


        /* =================================================
           DETERMINE THE SCOUT YEAR THAT JUST ENDED
           ================================================= */

        $yearEndTimestamp =
            $activeThroughTimestamp;


        $yearStartTimestamp =
            strtotime(
                '-1 year',
                $yearEndTimestamp
            );


        /*
         * During the Scout's first year, never count
         * activity from before they actually became a Scout.
         */

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


        /* =================================================
           COUNT ACCEPTED SCOUT REPORTS

           Only qualifying place_approved activity counts.

           occurred_at must fall inside the Scout year that
           just ended.

           Start is inclusive.
           End is exclusive.
           ================================================= */

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


        /* =================================================
           REQUIREMENT MET
           ================================================= */

        if (
            $acceptedReports
            >=
            SCOUT_REPORTS_REQUIRED
        ) {

            /*
             * IMPORTANT:
             *
             * Extend from the EXISTING active_through date.
             *
             * Do not use CURRENT_TIMESTAMP + 1 year.
             *
             * That preserves the Scout anniversary.
             *
             * Example:
             *
             * Aug 20, 2027
             * becomes
             * Aug 20, 2028
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
                      AND status = \'active\'
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
                    'Scout renewal update did not affect exactly one profile.'
                );

            }


            $db->commit();


            $renewedCount++;


            renewal_log(
                $displayName
                .
                ': renewed for one year with '
                .
                $acceptedReports
                .
                ' accepted Scout Report'
                .
                (
                    $acceptedReports === 1
                        ? ''
                        : 's'
                )
                .
                '.'
            );


            continue;

        }


        /* =================================================
           REQUIREMENT NOT MET

           The Scout keeps their history, profile, activity,
           application, training, and reports.

           Only current Scout status/access is deactivated.
           ================================================= */

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
                  AND status = \'active\'
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
                'Scout deactivation did not affect exactly one profile.'
            );

        }


        /* =================================================
           REMOVE SCOUT ACCESS ROLES

           This removes:
           scout
           master-scout
           master_scout

           It does NOT touch:
           owner
           admin
           member
           or any unrelated role.
           ================================================= */

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


        $db->commit();


        $inactiveCount++;


        renewal_log(
            $displayName
            .
            ': Scout access became inactive with '
            .
            $acceptedReports
            .
            ' of '
            .
            SCOUT_REPORTS_REQUIRED
            .
            ' required accepted Scout Reports.'
        );


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();

        }


        $errorCount++;


        renewal_log(
            $displayName
            .
            ': ERROR: '
            .
            $exception
                ->getMessage()
        );


        error_log(
            'Llama Scout renewal error for Scout profile '
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


/* =========================================================
   RUN SUMMARY
   ========================================================= */

renewal_log(
    'Scout renewal check finished.'
);


renewal_log(
    'Renewed: '
    .
    $renewedCount
);


renewal_log(
    'Made inactive: '
    .
    $inactiveCount
);


renewal_log(
    'Errors: '
    .
    $errorCount
);


/*
 * A non-zero exit status lets the server know something
 * failed, which will be useful when we add monitoring later.
 */

exit(
    $errorCount > 0
        ? 1
        : 0
);
