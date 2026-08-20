<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_role(
    'admin'
);


start_llama_session();


$adminUser =
    current_user();


$db =
    db();


$adminUserId =
    (int)
    $adminUser['id'];


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


function scout_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'invited' =>
            'Invited',

        'application_started' =>
            'About You Started',

        'application_submitted' =>
            'About You Complete',

        'training' =>
            'Training',

        'pending_approval' =>
            'Ready for Review',

        'active' =>
            'Active Scout',

        'inactive' =>
            'Inactive Scout',

        'declined' =>
            'Declined',

        'removed' =>
            'Removed',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-'
                    ],
                    ' ',
                    $status
                )
            )

    };

}


function format_admin_date(
    ?string $value,
    bool $withTime = false
): string {

    if (
        !$value
    ) {

        return 'Not yet';

    }


    $timestamp =
        strtotime(
            $value
        );


    if (
        $timestamp === false
    ) {

        return $value;

    }


    return date(
        $withTime
            ? 'M j, Y g:i A'
            : 'M j, Y',
        $timestamp
    );

}


function yes_no(
    mixed $value
): string {

    return !empty(
        $value
    )
        ? 'Yes'
        : 'No';

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


/* =========================================================
   SCOUT PROFILE ID
   ========================================================= */

$scoutProfileId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'scout_profile_id'
        ]
        ??
        0
    );


if (
    $scoutProfileId < 1
) {

    http_response_code(
        400
    );


    exit(
        'A valid Scout profile ID is required.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_scout_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_scout_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'admin_scout_csrf'
    ];


/* =========================================================
   LOAD SCOUT PROFILE + USER
   ========================================================= */

$scout =
    fetch_one(
        $db,
        '
        SELECT
            sp.id,
            sp.user_id,
            sp.status,

            sp.invited_by,
            sp.invited_at,
            sp.invitation_expires_at,

            sp.application_started_at,
            sp.application_submitted_at,

            sp.training_started_at,
            sp.training_completed_at,

            sp.approved_at,
            sp.approved_by,

            sp.scout_started_at,
            sp.active_through,

            sp.inactive_at,
            sp.removed_at,
            sp.removal_reason,
            sp.removed_by,

            sp.created_at,
            sp.updated_at,

            u.email,
            u.username,
            u.display_name,
            u.status
                AS account_status,

            u.email_verified_at,
            u.created_at
                AS account_created_at,
            u.last_login_at,

            u.membership_status,
            u.membership_interval,
            u.membership_started_at,
            u.membership_ends_at,

            u.stripe_customer_id,
            u.stripe_subscription_id,

            inviter.display_name
                AS inviter_display_name,
            inviter.username
                AS inviter_username,

            approver.display_name
                AS approver_display_name,
            approver.username
                AS approver_username

        FROM scout_profiles sp

        INNER JOIN users u
          ON u.id = sp.user_id

        LEFT JOIN users inviter
          ON inviter.id = sp.invited_by

        LEFT JOIN users approver
          ON approver.id = sp.approved_by

        WHERE sp.id = ?

        LIMIT 1
        ',
        [
            $scoutProfileId
        ]
    );


if (
    !$scout
) {

    http_response_code(
        404
    );


    exit(
        'Scout profile not found.'
    );

}


$scoutUserId =
    (int)
    $scout[
        'user_id'
    ];


/* =========================================================
   LOAD CURRENT ROLES
   ========================================================= */

$currentRoles =
    fetch_all(
        $db,
        '
        SELECT
            r.id,
            r.slug

        FROM roles r

        INNER JOIN user_roles ur
          ON ur.role_id = r.id

        WHERE ur.user_id = ?

        ORDER BY r.slug ASC
        ',
        [
            $scoutUserId
        ]
    );


$currentRoleSlugs =
    array_column(
        $currentRoles,
        'slug'
    );


$targetIsOwner =
    in_array(
        'owner',
        $currentRoleSlugs,
        true
    );


$targetIsAdmin =
    in_array(
        'admin',
        $currentRoleSlugs,
        true
    );


$targetIsScout =
    in_array(
        'scout',
        $currentRoleSlugs,
        true
    );


$targetIsMasterScout =
    in_array(
        'master-scout',
        $currentRoleSlugs,
        true
    )
    ||
    in_array(
        'master_scout',
        $currentRoleSlugs,
        true
    );


/* =========================================================
   LOAD APPLICATION
   ========================================================= */

$application =
    fetch_one(
        $db,
        '
        SELECT
            id,
            scout_profile_id,
            user_id,

            legal_name,
            address_line_1,
            address_line_2,
            city,
            state_region,
            postal_code,
            country,
            phone,

            why_scout,
            travel_experience,
            field_experience,
            accessibility_experience,
            sensory_experience,

            agrees_accuracy,
            agrees_safety,
            agrees_conduct,

            submitted_at,
            reviewed_at,
            reviewed_by,
            review_notes,

            created_at,
            updated_at

        FROM scout_applications

        WHERE scout_profile_id = ?
          AND user_id = ?

        LIMIT 1
        ',
        [
            $scoutProfileId,
            $scoutUserId
        ]
    );


/* =========================================================
   LOAD TRAINING
   ========================================================= */

$training =
    fetch_one(
        $db,
        '
        SELECT
            id,
            scout_profile_id,
            user_id,

            training_version,

            video_started_at,
            video_completed_at,

            acknowledged_tools,
            acknowledged_accuracy,
            acknowledged_safety,
            acknowledged_privacy,

            completed_at,

            created_at,
            updated_at

        FROM scout_training

        WHERE scout_profile_id = ?
          AND user_id = ?

        LIMIT 1
        ',
        [
            $scoutProfileId,
            $scoutUserId
        ]
    );


/* =========================================================
   CONTRIBUTION STATS
   ========================================================= */

$submissionStats =
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN status = \'approved\'
                    THEN 1
                    ELSE 0
                END
            ) AS approved,

            SUM(
                CASE
                    WHEN status = \'pending\'
                    THEN 1
                    ELSE 0
                END
            ) AS pending,

            SUM(
                CASE
                    WHEN status = \'needs-changes\'
                    THEN 1
                    ELSE 0
                END
            ) AS needs_changes,

            SUM(
                CASE
                    WHEN status = \'rejected\'
                    THEN 1
                    ELSE 0
                END
            ) AS rejected

        FROM place_submissions

        WHERE user_id = ?
        ',
        [
            $scoutUserId
        ]
    );


$totalSubmissions =
    (int) (
        $submissionStats[
            'total'
        ]
        ?? 0
    );


$approvedSubmissions =
    (int) (
        $submissionStats[
            'approved'
        ]
        ?? 0
    );


$pendingSubmissions =
    (int) (
        $submissionStats[
            'pending'
        ]
        ?? 0
    );


$recentSubmissions =
    fetch_all(
        $db,
        '
        SELECT
            id,
            place_name,
            status,
            submitted_at,
            reviewed_at

        FROM place_submissions

        WHERE user_id = ?

        ORDER BY submitted_at DESC

        LIMIT 8
        ',
        [
            $scoutUserId
        ]
    );


/* =========================================================
   ACTIVITY
   ========================================================= */

$activityStats =
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*) AS activity_count,
            COALESCE(
                SUM(points),
                0
            ) AS total_points

        FROM scout_activity

        WHERE scout_profile_id = ?
          AND user_id = ?
        ',
        [
            $scoutProfileId,
            $scoutUserId
        ]
    );


/* =========================================================
   READINESS
   ========================================================= */

$hasApplication =
    !empty(
        $application
    );


$applicationSubmitted =
    $hasApplication
    &&
    !empty(
        $application[
            'submitted_at'
        ]
    );


$trainingCompleted =
    !empty(
        $training[
            'completed_at'
        ]
    )
    &&
    !empty(
        $training[
            'video_completed_at'
        ]
    );


$allTrainingAcknowledgements =
    !empty(
        $training[
            'acknowledged_tools'
        ]
    )
    &&
    !empty(
        $training[
            'acknowledged_accuracy'
        ]
    )
    &&
    !empty(
        $training[
            'acknowledged_safety'
        ]
    )
    &&
    !empty(
        $training[
            'acknowledged_privacy'
        ]
    );


$isReadyForApproval =
    $scout[
        'status'
    ] ===
        'pending_approval'
    &&
    $applicationSubmitted
    &&
    $trainingCompleted
    &&
    $allTrainingAcknowledgements;


/* =========================================================
   POST ACTIONS
   ========================================================= */

$message =
    '';


$error =
    '';


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
                    ?? ''
                )
            );


        $reviewNotes =
            trim(
                (string) (
                    $_POST[
                        'review_notes'
                    ]
                    ?? ''
                )
            );


        /* =================================================
           APPROVE
           ================================================= */

        if (
            $action ===
            'approve'
        ) {

            /*
             * Re-check everything immediately before approval.
             */

            $freshProfile =
                fetch_one(
                    $db,
                    '
                    SELECT
                        status

                    FROM scout_profiles

                    WHERE id = ?
                      AND user_id = ?

                    LIMIT 1
                    ',
                    [
                        $scoutProfileId,
                        $scoutUserId
                    ]
                );


            $freshApplication =
                fetch_one(
                    $db,
                    '
                    SELECT
                        id,
                        submitted_at

                    FROM scout_applications

                    WHERE scout_profile_id = ?
                      AND user_id = ?

                    LIMIT 1
                    ',
                    [
                        $scoutProfileId,
                        $scoutUserId
                    ]
                );


            $freshTraining =
                fetch_one(
                    $db,
                    '
                    SELECT
                        id,
                        video_completed_at,
                        acknowledged_tools,
                        acknowledged_accuracy,
                        acknowledged_safety,
                        acknowledged_privacy,
                        completed_at

                    FROM scout_training

                    WHERE scout_profile_id = ?
                      AND user_id = ?

                    LIMIT 1
                    ',
                    [
                        $scoutProfileId,
                        $scoutUserId
                    ]
                );


            $freshReady =
                (
                    (
                        $freshProfile[
                            'status'
                        ]
                        ?? ''
                    )
                    ===
                    'pending_approval'
                )
                &&
                !empty(
                    $freshApplication[
                        'submitted_at'
                    ]
                )
                &&
                !empty(
                    $freshTraining[
                        'video_completed_at'
                    ]
                )
                &&
                !empty(
                    $freshTraining[
                        'completed_at'
                    ]
                )
                &&
                !empty(
                    $freshTraining[
                        'acknowledged_tools'
                    ]
                )
                &&
                !empty(
                    $freshTraining[
                        'acknowledged_accuracy'
                    ]
                )
                &&
                !empty(
                    $freshTraining[
                        'acknowledged_safety'
                    ]
                )
                &&
                !empty(
                    $freshTraining[
                        'acknowledged_privacy'
                    ]
                );


            if (
                !$freshReady
            ) {

                $error =
                    'This Scout candidate has not completed all onboarding requirements.';


            } else {

                try {

                    $db->beginTransaction();


                    /* =========================================
                       FIND SCOUT ROLE
                       ========================================= */

                    $roleStmt =
                        $db->prepare(
                            '
                            SELECT id

                            FROM roles

                            WHERE slug = \'scout\'

                            LIMIT 1
                            '
                        );


                    $roleStmt->execute();


                    $scoutRoleId =
                        (int)
                        $roleStmt
                            ->fetchColumn();


                    if (
                        $scoutRoleId < 1
                    ) {

                        throw new RuntimeException(
                            'The Scout role does not exist.'
                        );

                    }


                    /* =========================================
                       ASSIGN SCOUT ROLE
                       ========================================= */

                    $roleInsert =
                        $db->prepare(
                            '
                            INSERT INTO user_roles
                            (
                                user_id,
                                role_id
                            )

                            SELECT
                                ?,
                                ?

                            WHERE NOT EXISTS
                            (
                                SELECT 1

                                FROM user_roles

                                WHERE user_id = ?
                                  AND role_id = ?
                            )
                            '
                        );


                    $roleInsert->execute([
                        $scoutUserId,
                        $scoutRoleId,
                        $scoutUserId,
                        $scoutRoleId
                    ]);


                    /* =========================================
                       ACTIVATE SCOUT PROFILE

                       active_through starts one year from the
                       approval date. Activity logic can extend
                       this later.
                       ========================================= */

                    $profileUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_profiles

                            SET
                                status =
                                    \'active\',

                                approved_at =
                                    COALESCE(
                                        approved_at,
                                        CURRENT_TIMESTAMP
                                    ),

                                approved_by =
                                    ?,

                                scout_started_at =
                                    COALESCE(
                                        scout_started_at,
                                        CURRENT_TIMESTAMP
                                    ),

                                active_through =
                                    DATE_ADD(
                                        CURRENT_TIMESTAMP,
                                        INTERVAL 12 MONTH
                                    ),

                                inactive_at =
                                    NULL,

                                removed_at =
                                    NULL,

                                removal_reason =
                                    NULL,

                                removed_by =
                                    NULL,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id = ?
                              AND user_id = ?
                            '
                        );


                    $profileUpdate->execute([
                        $adminUserId,
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    /* =========================================
                       APPLICATION REVIEW
                       ========================================= */

                    $applicationUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_applications

                            SET
                                reviewed_at =
                                    CURRENT_TIMESTAMP,

                                reviewed_by =
                                    ?,

                                review_notes =
                                    ?

                            WHERE scout_profile_id = ?
                              AND user_id = ?
                            '
                        );


                    $applicationUpdate->execute([
                        $adminUserId,
                        $reviewNotes !== ''
                            ? $reviewNotes
                            : null,
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    /* =========================================
                       COMPLIMENTARY MEMBERSHIP

                       Do not overwrite an active Stripe
                       subscription. That could leave Stripe
                       charging while our database says
                       complimentary.

                       Free/canceled accounts can safely
                       transition immediately.
                       ========================================= */

                    $membershipStmt =
                        $db->prepare(
                            '
                            SELECT
                                membership_status,
                                stripe_subscription_id

                            FROM users

                            WHERE id = ?

                            LIMIT 1
                            '
                        );


                    $membershipStmt->execute([
                        $scoutUserId
                    ]);


                    $membershipRow =
                        $membershipStmt->fetch(
                            PDO::FETCH_ASSOC
                        )
                        ?: [];


                    $existingMembershipStatus =
                        strtolower(
                            trim(
                                (string) (
                                    $membershipRow[
                                        'membership_status'
                                    ]
                                    ?? 'none'
                                )
                            )
                        );


                    $paidMembershipStatuses = [
                        'active',
                        'trialing',
                        'past_due'
                    ];


                    $hasPaidMembership =
                        in_array(
                            $existingMembershipStatus,
                            $paidMembershipStatuses,
                            true
                        )
                        ||
                        !empty(
                            $membershipRow[
                                'stripe_subscription_id'
                            ]
                        );


                    if (
                        !$hasPaidMembership
                    ) {

                        $membershipUpdate =
                            $db->prepare(
                                '
                                UPDATE users

                                SET
                                    membership_status =
                                        \'complimentary\',

                                    membership_interval =
                                        NULL,

                                    membership_started_at =
                                        COALESCE(
                                            membership_started_at,
                                            CURRENT_TIMESTAMP
                                        ),

                                    membership_ends_at =
                                        DATE_ADD(
                                            CURRENT_TIMESTAMP,
                                            INTERVAL 12 MONTH
                                        )

                                WHERE id = ?
                                '
                            );


                        $membershipUpdate->execute([
                            $scoutUserId
                        ]);

                    }


                    $db->commit();


                    if (
                        $hasPaidMembership
                    ) {

                        $message =
                            'Scout approved and activated. Their existing paid Stripe membership was preserved so billing is not changed accidentally.';


                    } else {

                        $message =
                            'Scout approved. Scout role and complimentary membership are now active.';

                    }


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();

                    }


                    error_log(
                        'Llama Scout Scout approval error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The Scout could not be approved.';

                }

            }


        /* =================================================
           RETURN FOR CHANGES
           ================================================= */

        } elseif (
            $action ===
            'return'
        ) {

            if (
                $reviewNotes === ''
            ) {

                $error =
                    'Add a note explaining what needs to be changed.';


            } elseif (
                $scout[
                    'status'
                ] ===
                'active'
            ) {

                $error =
                    'An active Scout cannot be returned to onboarding from this screen.';


            } else {

                try {

                    $db->beginTransaction();


                    $profileUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_profiles

                            SET
                                status =
                                    \'application_started\',

                                application_submitted_at =
                                    NULL,

                                training_started_at =
                                    NULL,

                                training_completed_at =
                                    NULL,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id = ?
                              AND user_id = ?
                            '
                        );


                    $profileUpdate->execute([
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    $applicationUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_applications

                            SET
                                reviewed_at =
                                    CURRENT_TIMESTAMP,

                                reviewed_by =
                                    ?,

                                review_notes =
                                    ?

                            WHERE scout_profile_id = ?
                              AND user_id = ?
                            '
                        );


                    $applicationUpdate->execute([
                        $adminUserId,
                        $reviewNotes,
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    /*
                     * Preserve the training record as history,
                     * but clear completion so the candidate must
                     * go through training again after changes.
                     */

                    $trainingUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_training

                            SET
                                video_started_at =
                                    NULL,

                                video_completed_at =
                                    NULL,

                                acknowledged_tools =
                                    0,

                                acknowledged_accuracy =
                                    0,

                                acknowledged_safety =
                                    0,

                                acknowledged_privacy =
                                    0,

                                completed_at =
                                    NULL,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE scout_profile_id = ?
                              AND user_id = ?
                            '
                        );


                    $trainingUpdate->execute([
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    $db->commit();


                    $message =
                        'The Scout candidate has been returned to the About You step with your review note.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();

                    }


                    error_log(
                        'Llama Scout Scout return error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The Scout candidate could not be returned for changes.';

                }

            }


        /* =================================================
           DECLINE
           ================================================= */

        } elseif (
            $action ===
            'decline'
        ) {

            if (
                $reviewNotes === ''
            ) {

                $error =
                    'Add a review note before declining the Scout invitation.';


            } elseif (
                $scout[
                    'status'
                ] ===
                'active'
            ) {

                $error =
                    'Use Scout management to deactivate an active Scout rather than declining their onboarding.';


            } else {

                try {

                    $db->beginTransaction();


                    $profileUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_profiles

                            SET
                                status =
                                    \'declined\',

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id = ?
                              AND user_id = ?
                            '
                        );


                    $profileUpdate->execute([
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    $applicationUpdate =
                        $db->prepare(
                            '
                            UPDATE scout_applications

                            SET
                                reviewed_at =
                                    CURRENT_TIMESTAMP,

                                reviewed_by =
                                    ?,

                                review_notes =
                                    ?

                            WHERE scout_profile_id = ?
                              AND user_id = ?
                            '
                        );


                    $applicationUpdate->execute([
                        $adminUserId,
                        $reviewNotes,
                        $scoutProfileId,
                        $scoutUserId
                    ]);


                    $db->commit();


                    $message =
                        'Scout onboarding declined. Their regular Llama Scout account is unchanged.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();

                    }


                    error_log(
                        'Llama Scout Scout decline error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The Scout onboarding could not be declined.';

                }

            }


        } else {

            $error =
                'That Scout review action was not valid.';

        }

    }


    /* =====================================================
       RELOAD CURRENT STATE AFTER POST
       ===================================================== */

    $scout =
        fetch_one(
            $db,
            '
            SELECT
                sp.*,

                u.email,
                u.username,
                u.display_name,
                u.status
                    AS account_status,

                u.email_verified_at,
                u.created_at
                    AS account_created_at,
                u.last_login_at,

                u.membership_status,
                u.membership_interval,
                u.membership_started_at,
                u.membership_ends_at,

                u.stripe_customer_id,
                u.stripe_subscription_id,

                inviter.display_name
                    AS inviter_display_name,
                inviter.username
                    AS inviter_username,

                approver.display_name
                    AS approver_display_name,
                approver.username
                    AS approver_username

            FROM scout_profiles sp

            INNER JOIN users u
              ON u.id = sp.user_id

            LEFT JOIN users inviter
              ON inviter.id = sp.invited_by

            LEFT JOIN users approver
              ON approver.id = sp.approved_by

            WHERE sp.id = ?

            LIMIT 1
            ',
            [
                $scoutProfileId
            ]
        );


    $application =
        fetch_one(
            $db,
            '
            SELECT *

            FROM scout_applications

            WHERE scout_profile_id = ?
              AND user_id = ?

            LIMIT 1
            ',
            [
                $scoutProfileId,
                $scoutUserId
            ]
        );


    $training =
        fetch_one(
            $db,
            '
            SELECT *

            FROM scout_training

            WHERE scout_profile_id = ?
              AND user_id = ?

            LIMIT 1
            ',
            [
                $scoutProfileId,
                $scoutUserId
            ]
        );


    $currentRoles =
        fetch_all(
            $db,
            '
            SELECT
                r.id,
                r.slug

            FROM roles r

            INNER JOIN user_roles ur
              ON ur.role_id = r.id

            WHERE ur.user_id = ?

            ORDER BY r.slug ASC
            ',
            [
                $scoutUserId
            ]
        );


    $currentRoleSlugs =
        array_column(
            $currentRoles,
            'slug'
        );


    $targetIsScout =
        in_array(
            'scout',
            $currentRoleSlugs,
            true
        );


    $targetIsMasterScout =
        in_array(
            'master-scout',
            $currentRoleSlugs,
            true
        )
        ||
        in_array(
            'master_scout',
            $currentRoleSlugs,
            true
        );


    $applicationSubmitted =
        !empty(
            $application[
                'submitted_at'
            ]
        );


    $trainingCompleted =
        !empty(
            $training[
                'completed_at'
            ]
        )
        &&
        !empty(
            $training[
                'video_completed_at'
            ]
        );


    $allTrainingAcknowledgements =
        !empty(
            $training[
                'acknowledged_tools'
            ]
        )
        &&
        !empty(
            $training[
                'acknowledged_accuracy'
            ]
        )
        &&
        !empty(
            $training[
                'acknowledged_safety'
            ]
        )
        &&
        !empty(
            $training[
                'acknowledged_privacy'
            ]
        );


    $isReadyForApproval =
        (
            $scout[
                'status'
            ]
            ?? ''
        )
        ===
        'pending_approval'
        &&
        $applicationSubmitted
        &&
        $trainingCompleted
        &&
        $allTrainingAcknowledgements;

}


/* =========================================================
   DISPLAY VALUES
   ========================================================= */

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
        )
    );


$inviterName =
    trim(
        (string) (
            $scout[
                'inviter_display_name'
            ]
            ?:
            $scout[
                'inviter_username'
            ]
            ?:
            ''
        )
    );


$approverName =
    trim(
        (string) (
            $scout[
                'approver_display_name'
            ]
            ?:
            $scout[
                'approver_username'
            ]
            ?:
            ''
        )
    );


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
    <?= e($displayName) ?> | Scout Review | Llama Scout Admin
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


  <style>

    .scout-review-shell {
      width:
        min(
          100%,
          1120px
        );

      margin:
        0
        auto;

      padding:
        28px
        18px
        70px;
    }


    .scout-review-back {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        8px;

      margin-bottom:
        20px;

      text-decoration:
        none;
    }


    .scout-review-hero {
      display:
        grid;

      grid-template-columns:
        1fr
        auto;

      gap:
        24px;

      align-items:
        start;

      padding:
        clamp(
          24px,
          5vw,
          42px
        );

      border-radius:
        22px;

      background:
        linear-gradient(
          145deg,
          #10211b,
          #1c342a
        );

      color:
        #fff;
    }


    .scout-review-eyebrow {
      margin:
        0
        0
        8px;

      color:
        #d9c49a;

      font-size:
        .78rem;

      font-weight:
        800;

      letter-spacing:
        .12em;

      text-transform:
        uppercase;
    }


    .scout-review-hero h1 {
      margin:
        0
        0
        10px;

      color:
        #fff;

      font-size:
        clamp(
          2rem,
          5vw,
          3.4rem
        );

      line-height:
        1;

      letter-spacing:
        -.04em;
    }


    .scout-review-hero p {
      margin:
        0;

      max-width:
        680px;

      color:
        rgba(
          255,
          255,
          255,
          .76
        );

      line-height:
        1.6;
    }


    .scout-review-status {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        7px;

      padding:
        9px
        13px;

      border-radius:
        999px;

      background:
        rgba(
          255,
          255,
          255,
          .12
        );

      white-space:
        nowrap;

      font-weight:
        750;
    }


    .scout-review-notice {
      margin-top:
        20px;

      padding:
        15px
        18px;

      border-radius:
        12px;
    }


    .scout-review-notice.success {
      background:
        rgba(
          31,
          122,
          72,
          .12
        );
    }


    .scout-review-notice.error {
      background:
        rgba(
          174,
          52,
          52,
          .12
        );
    }


    .scout-review-grid {
      display:
        grid;

      grid-template-columns:
        minmax(
          0,
          1fr
        )
        330px;

      gap:
        22px;

      margin-top:
        22px;
    }


    .scout-review-main,
    .scout-review-side {
      display:
        grid;

      gap:
        18px;

      align-content:
        start;
    }


    .scout-review-card {
      padding:
        24px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
        );

      border-radius:
        16px;

      background:
        rgba(
          255,
          255,
          255,
          .84
        );
    }


    .scout-review-card h2 {
      margin:
        0
        0
        6px;

      font-size:
        1.3rem;
    }


    .scout-review-card > p {
      margin:
        0
        0
        20px;

      line-height:
        1.55;

      opacity:
        .75;
    }


    .review-answer {
      padding:
        16px
        0;

      border-top:
        1px solid
        rgba(
          23,
          40,
          34,
          .09
        );
    }


    .review-answer:first-of-type {
      border-top:
        0;
    }


    .review-answer strong {
      display:
        block;

      margin-bottom:
        6px;
    }


    .review-answer p {
      margin:
        0;

      white-space:
        pre-wrap;

      line-height:
        1.65;
    }


    .review-answer-empty {
      opacity:
        .55;

      font-style:
        italic;
    }


    .review-facts {
      display:
        grid;

      grid-template-columns:
        repeat(
          2,
          minmax(
            0,
            1fr
          )
        );

      gap:
        12px;
    }


    .review-fact {
      padding:
        14px;

      border-radius:
        11px;

      background:
        rgba(
          23,
          40,
          34,
          .055
        );
    }


    .review-fact span {
      display:
        block;

      margin-bottom:
        4px;

      font-size:
        .76rem;

      opacity:
        .65;
    }


    .review-fact strong {
      display:
        block;
    }


    .review-checklist {
      display:
        grid;

      gap:
        10px;
    }


    .review-check {
      display:
        flex;

      align-items:
        flex-start;

      gap:
        10px;

      padding:
        12px;

      border-radius:
        10px;

      background:
        rgba(
          23,
          40,
          34,
          .05
        );
    }


    .review-check.good i {
      color:
        #267447;
    }


    .review-check.bad i {
      color:
        #9b3434;
    }


    .review-submission {
      display:
        flex;

      justify-content:
        space-between;

      gap:
        14px;

      padding:
        12px
        0;

      border-top:
        1px solid
        rgba(
          23,
          40,
          34,
          .09
        );
    }


    .review-submission:first-of-type {
      border-top:
        0;
    }


    .review-submission-name {
      font-weight:
        700;
    }


    .review-submission-meta {
      margin-top:
        3px;

      font-size:
        .82rem;

      opacity:
        .67;
    }


    .review-role-list {
      display:
        flex;

      flex-wrap:
        wrap;

      gap:
        8px;
    }


    .review-role {
      padding:
        7px
        10px;

      border-radius:
        999px;

      background:
        rgba(
          23,
          40,
          34,
          .07
        );

      font-size:
        .82rem;

      font-weight:
        700;
    }


    .review-actions textarea {
      width:
        100%;

      min-height:
        130px;

      padding:
        12px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .18
        );

      border-radius:
        10px;

      font:
        inherit;

      line-height:
        1.55;

      resize:
        vertical;
    }


    .review-action-buttons {
      display:
        grid;

      gap:
        10px;

      margin-top:
        12px;
    }


    .review-action-button {
      width:
        100%;

      min-height:
        44px;

      padding:
        10px
        14px;

      border:
        0;

      border-radius:
        9px;

      font:
        inherit;

      font-weight:
        750;

      cursor:
        pointer;
    }


    .review-action-button.approve {
      background:
        #172822;

      color:
        #fff;
    }


    .review-action-button.return {
      background:
        #e7dcc4;

      color:
        #392e1c;
    }


    .review-action-button.decline {
      background:
        #8c3232;

      color:
        #fff;
    }


    .review-action-button:disabled {
      opacity:
        .45;

      cursor:
        not-allowed;
    }


    .review-private {
      display:
        flex;

      gap:
        9px;

      margin-bottom:
        15px;

      padding:
        12px;

      border-radius:
        10px;

      background:
        rgba(
          23,
          40,
          34,
          .055
        );

      font-size:
        .85rem;

      line-height:
        1.45;
    }


    @media (
      max-width:
        860px
    ) {

      .scout-review-grid {
        grid-template-columns:
          1fr;
      }


      .scout-review-hero {
        grid-template-columns:
          1fr;
      }


      .scout-review-status {
        justify-self:
          start;
      }

    }


    @media (
      max-width:
        560px
    ) {

      .review-facts {
        grid-template-columns:
          1fr;
      }

    }

  </style>

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="scout-review-shell">


  <a
    class="scout-review-back"
    href="/"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to Basecamp

  </a>


  <section class="scout-review-hero">


    <div>

      <p class="scout-review-eyebrow">
        Scout Review
      </p>


      <h1>
        <?= e($displayName) ?>
      </h1>


      <p>

        Review the candidate's account, contributions,
        About You responses, training completion, and Scout
        agreements before activating Scout access.

      </p>

    </div>


    <div class="scout-review-status">

      <i
        class="fa-solid fa-compass"
        aria-hidden="true"
      ></i>

      <?= e(
          scout_status_label(
              (string)
              $scout[
                  'status'
              ]
          )
      ) ?>

    </div>


  </section>


  <?php if ($message): ?>

    <div class="scout-review-notice success">
      <?= e($message) ?>
    </div>

  <?php endif; ?>


  <?php if ($error): ?>

    <div class="scout-review-notice error">
      <?= e($error) ?>
    </div>

  <?php endif; ?>


  <div class="scout-review-grid">


    <!-- ===================================================
         MAIN COLUMN
         =================================================== -->

    <div class="scout-review-main">


      <!-- ===============================================
           ABOUT YOU
           =============================================== -->

      <section class="scout-review-card">


        <h2>
          About You
        </h2>


        <p>
          What the candidate shared during Scout onboarding.
        </p>


        <?php if ($application): ?>


          <div class="review-answer">

            <strong>
              Why are they interested in becoming a Scout?
            </strong>

            <?php if (
                !empty(
                    $application[
                        'why_scout'
                    ]
                )
            ): ?>

              <p>
                <?= e(
                    $application[
                        'why_scout'
                    ]
                ) ?>
              </p>

            <?php else: ?>

              <p class="review-answer-empty">
                No answer provided.
              </p>

            <?php endif; ?>

          </div>


          <div class="review-answer">

            <strong>
              What does travel usually look like for them?
            </strong>

            <?php if (
                !empty(
                    $application[
                        'travel_experience'
                    ]
                )
            ): ?>

              <p>
                <?= e(
                    $application[
                        'travel_experience'
                    ]
                ) ?>
              </p>

            <?php else: ?>

              <p class="review-answer-empty">
                No answer provided.
              </p>

            <?php endif; ?>

          </div>


          <div class="review-answer">

            <strong>
              What kinds of things do they naturally notice?
            </strong>

            <?php if (
                !empty(
                    $application[
                        'field_experience'
                    ]
                )
            ): ?>

              <p>
                <?= e(
                    $application[
                        'field_experience'
                    ]
                ) ?>
              </p>

            <?php else: ?>

              <p class="review-answer-empty">
                No answer provided.
              </p>

            <?php endif; ?>

          </div>


          <div class="review-answer">

            <strong>
              Accessibility perspective
            </strong>

            <?php if (
                !empty(
                    $application[
                        'accessibility_experience'
                    ]
                )
            ): ?>

              <p>
                <?= e(
                    $application[
                        'accessibility_experience'
                    ]
                ) ?>
              </p>

            <?php else: ?>

              <p class="review-answer-empty">
                No answer provided.
              </p>

            <?php endif; ?>

          </div>


          <div class="review-answer">

            <strong>
              Sensory perspective
            </strong>

            <?php if (
                !empty(
                    $application[
                        'sensory_experience'
                    ]
                )
            ): ?>

              <p>
                <?= e(
                    $application[
                        'sensory_experience'
                    ]
                ) ?>
              </p>

            <?php else: ?>

              <p class="review-answer-empty">
                No answer provided.
              </p>

            <?php endif; ?>

          </div>


        <?php else: ?>

          <p class="review-answer-empty">
            This candidate has not completed the About You section.
          </p>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           CONTRIBUTIONS
           =============================================== -->

      <section class="scout-review-card">


        <h2>
          Community Contributions
        </h2>


        <p>
          Their contribution history before becoming a Scout.
        </p>


        <div class="review-facts">


          <div class="review-fact">

            <span>
              Total submissions
            </span>

            <strong>
              <?= $totalSubmissions ?>
            </strong>

          </div>


          <div class="review-fact">

            <span>
              Approved
            </span>

            <strong>
              <?= $approvedSubmissions ?>
            </strong>

          </div>


          <div class="review-fact">

            <span>
              Pending
            </span>

            <strong>
              <?= $pendingSubmissions ?>
            </strong>

          </div>


          <div class="review-fact">

            <span>
              Scout activity points
            </span>

            <strong>
              <?= (int) (
                  $activityStats[
                      'total_points'
                  ]
                  ?? 0
              ) ?>
            </strong>

          </div>


        </div>


        <?php if ($recentSubmissions): ?>


          <div
            style="
              margin-top: 18px;
            "
          >


            <?php foreach (
                $recentSubmissions
                as
                $submission
            ): ?>


              <div class="review-submission">


                <div>

                  <div class="review-submission-name">
                    <?= e(
                        $submission[
                            'place_name'
                        ]
                    ) ?>
                  </div>


                  <div class="review-submission-meta">

                    Submitted
                    <?= e(
                        format_admin_date(
                            $submission[
                                'submitted_at'
                            ]
                        )
                    ) ?>

                  </div>

                </div>


                <div>

                  <?= e(
                      ucwords(
                          str_replace(
                              '-',
                              ' ',
                              (string)
                              $submission[
                                  'status'
                              ]
                          )
                      )
                  ) ?>

                </div>


              </div>


            <?php endforeach; ?>


          </div>


        <?php endif; ?>


      </section>


      <!-- ===============================================
           PRIVATE CONTACT
           =============================================== -->

      <section class="scout-review-card">


        <h2>
          Private Scout Information
        </h2>


        <div class="review-private">

          <i
            class="fa-solid fa-lock"
            aria-hidden="true"
          ></i>

          <span>

            This information is private and should only be
            used for legitimate Scout administration.

          </span>

        </div>


        <?php if ($application): ?>


          <div class="review-facts">


            <div class="review-fact">

              <span>
                Legal name
              </span>

              <strong>
                <?= e(
                    $application[
                        'legal_name'
                    ]
                ) ?>
              </strong>

            </div>


            <div class="review-fact">

              <span>
                Phone
              </span>

              <strong>
                <?= e(
                    $application[
                        'phone'
                    ]
                    ?: 'Not provided'
                ) ?>
              </strong>

            </div>


            <div class="review-fact">

              <span>
                Address
              </span>

              <strong>

                <?= e(
                    $application[
                        'address_line_1'
                    ]
                ) ?>

                <?php if (
                    !empty(
                        $application[
                            'address_line_2'
                        ]
                    )
                ): ?>

                  <br>

                  <?= e(
                      $application[
                          'address_line_2'
                      ]
                  ) ?>

                <?php endif; ?>

              </strong>

            </div>


            <div class="review-fact">

              <span>
                City / Region
              </span>

              <strong>

                <?= e(
                    $application[
                        'city'
                    ]
                ) ?>,

                <?= e(
                    $application[
                        'state_region'
                    ]
                ) ?>

                <?= e(
                    $application[
                        'postal_code'
                    ]
                ) ?>

                <br>

                <?= e(
                    $application[
                        'country'
                    ]
                ) ?>

              </strong>

            </div>


          </div>


        <?php else: ?>

          <p class="review-answer-empty">
            No private Scout information has been submitted.
          </p>

        <?php endif; ?>


      </section>


    </div>


    <!-- ===================================================
         SIDEBAR
         =================================================== -->

    <aside class="scout-review-side">


      <!-- ===============================================
           READINESS
           =============================================== -->

      <section class="scout-review-card">


        <h2>
          Onboarding Check
        </h2>


        <p>
          Everything required before Scout activation.
        </p>


        <div class="review-checklist">


          <div class="
            review-check
            <?= $applicationSubmitted
                ? 'good'
                : 'bad'
            ?>
          ">

            <i
              class="<?= $applicationSubmitted
                  ? 'fa-solid fa-circle-check'
                  : 'fa-solid fa-circle-xmark'
              ?>"
              aria-hidden="true"
            ></i>

            <span>
              About You completed
            </span>

          </div>


          <div class="
            review-check
            <?= !empty(
                $training[
                    'video_completed_at'
                ]
            )
                ? 'good'
                : 'bad'
            ?>
          ">

            <i
              class="<?= !empty(
                  $training[
                      'video_completed_at'
                  ]
              )
                  ? 'fa-solid fa-circle-check'
                  : 'fa-solid fa-circle-xmark'
              ?>"
              aria-hidden="true"
            ></i>

            <span>
              Training video completed
            </span>

          </div>


          <div class="
            review-check
            <?= $allTrainingAcknowledgements
                ? 'good'
                : 'bad'
            ?>
          ">

            <i
              class="<?= $allTrainingAcknowledgements
                  ? 'fa-solid fa-circle-check'
                  : 'fa-solid fa-circle-xmark'
              ?>"
              aria-hidden="true"
            ></i>

            <span>
              Training acknowledgements complete
            </span>

          </div>


          <div class="
            review-check
            <?= !empty(
                $scout[
                    'email_verified_at'
                ]
            )
                ? 'good'
                : 'bad'
            ?>
          ">

            <i
              class="<?= !empty(
                  $scout[
                      'email_verified_at'
                  ]
              )
                  ? 'fa-solid fa-circle-check'
                  : 'fa-solid fa-circle-xmark'
              ?>"
              aria-hidden="true"
            ></i>

            <span>
              Email verified
            </span>

          </div>


        </div>


      </section>


      <!-- ===============================================
           ACCOUNT
           =============================================== -->

      <section class="scout-review-card">


        <h2>
          Account
        </h2>


        <div class="review-answer">

          <strong>
            Username
          </strong>

          <p>
            <?= e(
                $scout[
                    'username'
                ]
                ?: 'None'
            ) ?>
          </p>

        </div>


        <div class="review-answer">

          <strong>
            Email
          </strong>

          <p>
            <?= e(
                $scout[
                    'email'
                ]
            ) ?>
          </p>

        </div>


        <div class="review-answer">

          <strong>
            Membership
          </strong>

          <p>

            <?= e(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        (string) (
                            $scout[
                                'membership_status'
                            ]
                            ?: 'none'
                        )
                    )
                )
            ) ?>

          </p>

        </div>


        <div class="review-answer">

          <strong>
            Roles
          </strong>


          <div class="review-role-list">

            <?php if ($currentRoleSlugs): ?>

              <?php foreach (
                  $currentRoleSlugs
                  as
                  $role
              ): ?>

                <span class="review-role">

                  <?= e(
                      ucwords(
                          str_replace(
                              [
                                  '_',
                                  '-'
                              ],
                              ' ',
                              $role
                          )
                      )
                  ) ?>

                </span>

              <?php endforeach; ?>

            <?php else: ?>

              <span class="review-role">
                User
              </span>

            <?php endif; ?>

          </div>

        </div>


      </section>


      <!-- ===============================================
           TIMELINE
           =============================================== -->

      <section class="scout-review-card">


        <h2>
          Scout Timeline
        </h2>


        <div class="review-answer">

          <strong>
            Invited
          </strong>

          <p>
            <?= e(
                format_admin_date(
                    $scout[
                        'invited_at'
                    ]
                )
            ) ?>

            <?php if (
                $inviterName !== ''
            ): ?>

              by
              <?= e(
                  $inviterName
              ) ?>

            <?php endif; ?>

          </p>

        </div>


        <div class="review-answer">

          <strong>
            About You completed
          </strong>

          <p>
            <?= e(
                format_admin_date(
                    $scout[
                        'application_submitted_at'
                    ]
                )
            ) ?>
          </p>

        </div>


        <div class="review-answer">

          <strong>
            Training completed
          </strong>

          <p>
            <?= e(
                format_admin_date(
                    $scout[
                        'training_completed_at'
                    ]
                )
            ) ?>
          </p>

        </div>


        <?php if (
            !empty(
                $scout[
                    'approved_at'
                ]
            )
        ): ?>

          <div class="review-answer">

            <strong>
              Approved
            </strong>

            <p>

              <?= e(
                  format_admin_date(
                      $scout[
                          'approved_at'
                      ]
                  )
              ) ?>

              <?php if (
                  $approverName !== ''
              ): ?>

                by
                <?= e(
                    $approverName
                ) ?>

              <?php endif; ?>

            </p>

          </div>

        <?php endif; ?>


        <?php if (
            !empty(
                $scout[
                    'active_through'
                ]
            )
        ): ?>

          <div class="review-answer">

            <strong>
              Active through
            </strong>

            <p>
              <?= e(
                  format_admin_date(
                      $scout[
                          'active_through'
                      ]
                  )
              ) ?>
            </p>

          </div>

        <?php endif; ?>


      </section>


      <!-- ===============================================
           REVIEW ACTIONS
           =============================================== -->

      <?php if (
          $scout[
              'status'
          ]
          !==
          'active'
      ): ?>


        <section class="
          scout-review-card
          review-actions
        ">


          <h2>
            Review
          </h2>


          <p>

            Add an internal/candidate-facing review note if
            needed, then choose an action.

          </p>


          <form
            method="post"
            action="scout.php"
          >


            <input
              type="hidden"
              name="csrf_token"
              value="<?= e($csrfToken) ?>"
            >


            <input
              type="hidden"
              name="scout_profile_id"
              value="<?= $scoutProfileId ?>"
            >


            <textarea
              name="review_notes"
              placeholder="Review notes..."
            ><?= e(
                $application[
                    'review_notes'
                ]
                ?? ''
            ) ?></textarea>


            <div class="review-action-buttons">


              <button
                class="
                  review-action-button
                  approve
                "
                type="submit"
                name="action"
                value="approve"
                <?= !$isReadyForApproval
                    ? 'disabled'
                    : ''
                ?>
                onclick="
                  return confirm(
                    'Approve this candidate as a Llama Scout? Their Scout role and qualifying complimentary access will activate immediately.'
                  );
                "
              >

                <i
                  class="fa-solid fa-compass"
                  aria-hidden="true"
                ></i>

                Approve Scout

              </button>


              <button
                class="
                  review-action-button
                  return
                "
                type="submit"
                name="action"
                value="return"
              >

                <i
                  class="fa-solid fa-arrow-rotate-left"
                  aria-hidden="true"
                ></i>

                Return for Changes

              </button>


              <button
                class="
                  review-action-button
                  decline
                "
                type="submit"
                name="action"
                value="decline"
                onclick="
                  return confirm(
                    'Decline this Scout onboarding request? Their regular Llama Scout account will remain unchanged.'
                  );
                "
              >

                <i
                  class="fa-solid fa-xmark"
                  aria-hidden="true"
                ></i>

                Decline

              </button>


            </div>


          </form>


        </section>


      <?php else: ?>


        <section class="scout-review-card">


          <h2>
            Scout Active
          </h2>


          <p>

            This account has completed onboarding and has an
            active Scout role.

          </p>


          <div class="review-check good">

            <i
              class="fa-solid fa-circle-check"
              aria-hidden="true"
            ></i>

            <span>
              Scout access active
            </span>

          </div>


        </section>


      <?php endif; ?>


    </aside>


  </div>


</main>


<script src="https://llamascout.com/js/header.js"></script>


</body>

</html>
