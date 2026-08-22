<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/stripe.php';
require_once dirname(__DIR__) . '/app/timezone.php';
require_once dirname(__DIR__) . '/app/scout-maintenance.php';
require_once dirname(__DIR__) . '/app/role-display.php';

require_role('admin');
start_llama_session();

$adminUser = current_user();
$db = db();
$adminUserId = (int) $adminUser['id'];
$primaryRoleLabel =
    llama_primary_role_label(
        $adminUserId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $adminUserId
    );


/* =========================================================
   TRAINING CONFIGURATION

   Keep this value synchronized with TRAINING_VERSION in
   account/scout-training.php.
   ========================================================= */

const SCOUT_REQUIRED_TRAINING_VERSION = '1';


/* =========================================================
   HELPERS
   ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function scout_status_label(
    string $status
): string {

    return match ($status) {

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
            ),
    };
}


function scout_status_badge_class(
    string $status
): string {

    return match ($status) {

        'active' =>
            'admin-badge--success',

        'pending_approval' =>
            'admin-badge--warning',

        'inactive',
        'declined',
        'removed' =>
            'admin-badge--danger',

        default =>
            'admin-badge--muted',
    };
}


function scout_extension_status_label(
    string $status
): string {

    return match ($status) {

        'active' =>
            'Active Extension',

        'completed' =>
            'Completed',

        'failed' =>
            'Expired',

        'canceled' =>
            'Canceled',

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
            ),
    };
}


function format_admin_date(
    ?string $value,
    bool $withTime = false
): string {

    global $adminUser;


    if (!$value) {

        return 'Not yet';
    }


    return llama_format_datetime(
        $value,
        llama_user_timezone(
            $adminUser
        ),
        $withTime
            ? 'M j, Y g:i A'
            : 'M j, Y'
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


    return
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
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


function load_scout(
    PDO $db,
    int $scoutProfileId
): array {

    return fetch_one(
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
            u.stripe_cancel_at_period_end,

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
}


function load_role_slugs(
    PDO $db,
    int $userId
): array {

    $roles =
        fetch_all(
            $db,
            '
            SELECT
                r.slug

            FROM roles r

            INNER JOIN user_roles ur
              ON ur.role_id = r.id

            WHERE ur.user_id = ?

            ORDER BY
                r.slug ASC
            ',
            [
                $userId
            ]
        );


    return array_column(
        $roles,
        'slug'
    );
}


function load_application(
    PDO $db,
    int $scoutProfileId,
    int $userId
): array {

    return fetch_one(
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
            $userId
        ]
    );
}


function load_training(
    PDO $db,
    int $scoutProfileId,
    int $userId
): array {

    return fetch_one(
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
            $userId
        ]
    );
}


function load_latest_scout_extension(
    PDO $db,
    int $scoutProfileId,
    int $userId
): array {

    return fetch_one(
        $db,
        '
        SELECT
            se.*,

            granter.display_name
                AS granted_by_display_name,

            granter.username
                AS granted_by_username

        FROM scout_extensions se

        LEFT JOIN users granter
          ON granter.id = se.granted_by

        WHERE se.scout_profile_id = ?
          AND se.user_id = ?

        ORDER BY
            se.id DESC

        LIMIT 1
        ',
        [
            $scoutProfileId,
            $userId
        ]
    );
}


function scout_application_submitted(
    array $application
): bool {

    return !empty(
        $application[
            'submitted_at'
        ]
    );
}


function scout_training_version_current(
    array $training
): bool {

    return
        (string) (
            $training[
                'training_version'
            ]
            ?? ''
        )
        ===
        SCOUT_REQUIRED_TRAINING_VERSION;
}


function scout_training_completed(
    array $training
): bool {

    return
        scout_training_version_current(
            $training
        )
        &&
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
}


function scout_acknowledgements_complete(
    array $training
): bool {

    return
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
}


function scout_is_ready_for_approval(
    array $scout,
    array $application,
    array $training
): bool {

    return
        (
            (string) (
                $scout[
                    'status'
                ]
                ?? ''
            )
            ===
            'pending_approval'
        )
        &&
        !empty(
            $scout[
                'email_verified_at'
            ]
        )
        &&
        scout_application_submitted(
            $application
        )
        &&
        scout_training_completed(
            $training
        )
        &&
        scout_acknowledgements_complete(
            $training
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
    $scoutProfileId
    <
    1
) {

    http_response_code(
        400
    );


    exit(
        'A valid Scout profile ID is required.'
    );
}


/* =========================================================
   ENSURE EXTENSION STORAGE
   ========================================================= */

llama_ensure_scout_extensions_table(
    $db
);


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
   INITIAL LOAD
   ========================================================= */

$scout =
    load_scout(
        $db,
        $scoutProfileId
    );


if (!$scout) {

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


$application =
    load_application(
        $db,
        $scoutProfileId,
        $scoutUserId
    );


$training =
    load_training(
        $db,
        $scoutProfileId,
        $scoutUserId
    );


$currentRoleSlugs =
    load_role_slugs(
        $db,
        $scoutUserId
    );


$latestExtension =
    load_latest_scout_extension(
        $db,
        $scoutProfileId,
        $scoutUserId
    );


$applicationSubmitted =
    scout_application_submitted(
        $application
    );


$trainingVersionCurrent =
    scout_training_version_current(
        $training
    );


$trainingCompleted =
    scout_training_completed(
        $training
    );


$allTrainingAcknowledgements =
    scout_acknowledgements_complete(
        $training
    );


$emailVerified =
    !empty(
        $scout[
            'email_verified_at'
        ]
    );


$isReadyForApproval =
    scout_is_ready_for_approval(
        $scout,
        $application,
        $training
    );


/* =========================================================
   POST ACTIONS
   ========================================================= */

$message = '';
$error = '';


$billingResult =
    trim(
        (string) (
            $_GET[
                'billing'
            ]
            ?? ''
        )
    );


if (
    $billingResult
    !==
    ''
) {

    switch (
        $billingResult
    ) {

        case 'scout-scheduled':

            $message =
                'Paid membership is now scheduled not to renew at the end of the current billing period.';

            break;


        case 'scout-already-scheduled':

            $message =
                'Paid membership was already scheduled not to renew.';

            break;


        case 'scout-already-ended':

            $message =
                'The previous paid membership has already ended.';

            break;


        case 'scout-no-subscription':

            $message =
                'There is no active Stripe subscription to change.';

            break;


        case 'scout-ok':

            $message =
                'Scout billing was updated successfully.';

            break;


        case 'scout-error':

            $error =
                'The Scout billing change could not be completed. Check the server error log for details.';

            break;
    }
}


if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
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
            $action
            ===
            'approve'
        ) {

            try {

                $db->beginTransaction();


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

                        FOR UPDATE
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
                        SELECT *

                        FROM scout_applications

                        WHERE scout_profile_id = ?
                          AND user_id = ?

                        LIMIT 1

                        FOR UPDATE
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
                        SELECT *

                        FROM scout_training

                        WHERE scout_profile_id = ?
                          AND user_id = ?

                        LIMIT 1

                        FOR UPDATE
                        ',
                        [
                            $scoutProfileId,
                            $scoutUserId
                        ]
                    );


                $freshUser =
                    fetch_one(
                        $db,
                        '
                        SELECT
                            email_verified_at,
                            membership_status,
                            stripe_subscription_id

                        FROM users

                        WHERE id = ?

                        LIMIT 1

                        FOR UPDATE
                        ',
                        [
                            $scoutUserId
                        ]
                    );


                $freshScoutForReadiness = [

                    'status' =>
                        $freshProfile[
                            'status'
                        ]
                        ?? '',

                    'email_verified_at' =>
                        $freshUser[
                            'email_verified_at'
                        ]
                        ?? null,
                ];


                if (
                    !scout_is_ready_for_approval(
                        $freshScoutForReadiness,
                        $freshApplication,
                        $freshTraining
                    )
                ) {

                    throw new RuntimeException(
                        'This Scout candidate has not completed all current onboarding requirements.'
                    );
                }


                $roleStmt =
                    $db->prepare(
                        '
                        SELECT
                            id

                        FROM roles

                        WHERE slug =
                            \'scout\'

                        LIMIT 1
                        '
                    );


                $roleStmt->execute();


                $scoutRoleId =
                    (int)
                    $roleStmt
                        ->fetchColumn();


                if (
                    $scoutRoleId
                    <
                    1
                ) {

                    throw new RuntimeException(
                        'The Scout role does not exist.'
                    );
                }


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
                                    INTERVAL 1 YEAR
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
                          AND status =
                              \'pending_approval\'
                        '
                    );


                $profileUpdate->execute([
                    $adminUserId,
                    $scoutProfileId,
                    $scoutUserId
                ]);


                if (
                    $profileUpdate->rowCount()
                    !==
                    1
                ) {

                    throw new RuntimeException(
                        'Scout onboarding state changed before approval could be completed.'
                    );
                }


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


                $existingMembershipStatus =
                    strtolower(
                        trim(
                            (string) (
                                $freshUser[
                                    'membership_status'
                                ]
                                ?? 'none'
                            )
                        )
                    );


                $hasPaidMembership =
                    in_array(
                        $existingMembershipStatus,
                        [
                            'active',
                            'trialing',
                            'past_due'
                        ],
                        true
                    )
                    &&
                    !empty(
                        $freshUser[
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
                                        INTERVAL 1 YEAR
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

                    try {

                        $billingTransition =
                            llama_schedule_subscription_end_for_scout(
                                $db,
                                $scoutUserId
                            );


                        $billingReason =
                            (string) (
                                $billingTransition[
                                    'reason'
                                ]
                                ?? ''
                            );


                        $message =
                            match (
                                $billingReason
                            ) {

                                'scheduled' =>
                                    'Scout approved and activated. Their paid membership will remain active through the current billing period and will not renew. Scout access is already active.',

                                'already_scheduled' =>
                                    'Scout approved and activated. Their paid membership was already scheduled not to renew.',

                                'already_ended' =>
                                    'Scout approved and activated. Their previous paid subscription has already ended.',

                                'no_subscription' =>
                                    'Scout approved and activated. No active Stripe subscription required a billing change.',

                                default =>
                                    'Scout approved and activated. Billing transition completed.',
                            };


                    } catch (
                        Throwable $billingException
                    ) {

                        error_log(
                            'Llama Scout Scout billing transition error for user #'
                            .
                            $scoutUserId
                            .
                            ': '
                            .
                            $billingException
                                ->getMessage()
                        );


                        $message =
                            'Scout approved and activated, but their paid Stripe subscription could not be scheduled to end. Scout access is active. Billing needs attention.';
                    }


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
                    $exception->getMessage()
                    ===
                    'This Scout candidate has not completed all current onboarding requirements.'
                        ? $exception->getMessage()
                        : 'The Scout could not be approved.';
            }


        /* =================================================
           GRANT 30-DAY SCOUT EXTENSION
           ================================================= */

        } elseif (
            $action
            ===
            'grant_extension'
        ) {

            try {

                $db->beginTransaction();


                /* =========================================
                   LOCK SCOUT PROFILE
                   ========================================= */

                $freshProfile =
                    fetch_one(
                        $db,
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
                        ',
                        [
                            $scoutProfileId,
                            $scoutUserId
                        ]
                    );


                if (
                    !$freshProfile
                    ||
                    (
                        (string) (
                            $freshProfile[
                                'status'
                            ]
                            ?? ''
                        )
                        !==
                        'inactive'
                    )
                ) {

                    throw new RuntimeException(
                        'Only an inactive former Scout can receive a 30-day Scout extension.'
                    );
                }


                /* =========================================
                   PREVENT OVERLAPPING EXTENSIONS
                   ========================================= */

                $activeExtension =
                    fetch_one(
                        $db,
                        '
                        SELECT
                            id

                        FROM scout_extensions

                        WHERE scout_profile_id = ?
                          AND user_id = ?
                          AND status =
                              \'active\'

                        ORDER BY id DESC

                        LIMIT 1

                        FOR UPDATE
                        ',
                        [
                            $scoutProfileId,
                            $scoutUserId
                        ]
                    );


                if (
                    $activeExtension
                ) {

                    throw new RuntimeException(
                        'This Scout already has an active extension.'
                    );
                }


                /* =========================================
                   LOCK USER
                   ========================================= */

                $freshUser =
                    fetch_one(
                        $db,
                        '
                        SELECT
                            id,
                            membership_status,
                            stripe_subscription_id

                        FROM users

                        WHERE id = ?

                        LIMIT 1

                        FOR UPDATE
                        ',
                        [
                            $scoutUserId
                        ]
                    );


                if (
                    !$freshUser
                ) {

                    throw new RuntimeException(
                        'The Scout account could not be found.'
                    );
                }


                /* =========================================
                   EXACT EXTENSION WINDOW
                   ========================================= */

                $extensionWindow =
                    fetch_one(
                        $db,
                        '
                        SELECT
                            CURRENT_TIMESTAMP
                                AS started_at,

                            DATE_ADD(
                                CURRENT_TIMESTAMP,
                                INTERVAL 30 DAY
                            )
                                AS ends_at
                        '
                    );


                $extensionStartedAt =
                    trim(
                        (string) (
                            $extensionWindow[
                                'started_at'
                            ]
                            ?? ''
                        )
                    );


                $extensionEndsAt =
                    trim(
                        (string) (
                            $extensionWindow[
                                'ends_at'
                            ]
                            ?? ''
                        )
                    );


                if (
                    $extensionStartedAt === ''
                    ||
                    $extensionEndsAt === ''
                ) {

                    throw new RuntimeException(
                        'The Scout extension dates could not be determined.'
                    );
                }


                /* =========================================
                   CREATE EXTENSION RECORD
                   ========================================= */

                $extensionInsert =
                    $db->prepare(
                        '
                        INSERT INTO scout_extensions
                        (
                            scout_profile_id,
                            user_id,
                            granted_by,
                            started_at,
                            ends_at,
                            status,
                            accepted_reports
                        )

                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            \'active\',
                            0
                        )
                        '
                    );


                $extensionInsert->execute([
                    $scoutProfileId,
                    $scoutUserId,
                    $adminUserId,
                    $extensionStartedAt,
                    $extensionEndsAt
                ]);


                if (
                    $extensionInsert->rowCount()
                    !==
                    1
                ) {

                    throw new RuntimeException(
                        'The Scout extension record could not be created.'
                    );
                }


                /* =========================================
                   REACTIVATE PROFILE FOR EXACTLY 30 DAYS
                   ========================================= */

                $profileUpdate =
                    $db->prepare(
                        '
                        UPDATE scout_profiles

                        SET
                            status =
                                \'active\',

                            active_through =
                                ?,

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
                          AND status =
                              \'inactive\'
                        '
                    );


                $profileUpdate->execute([
                    $extensionEndsAt,
                    $scoutProfileId,
                    $scoutUserId
                ]);


                if (
                    $profileUpdate->rowCount()
                    !==
                    1
                ) {

                    throw new RuntimeException(
                        'The Scout profile changed before the extension could be granted.'
                    );
                }


                /* =========================================
                   REMOVE OLD SCOUT RANKS

                   A former Master Scout always returns as a
                   basic Scout.
                   ========================================= */

                llama_remove_scout_roles(
                    $db,
                    $scoutUserId
                );


                /* =========================================
                   FIND BASIC SCOUT ROLE
                   ========================================= */

                $roleStmt =
                    $db->prepare(
                        '
                        SELECT
                            id

                        FROM roles

                        WHERE slug =
                            \'scout\'

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
                   ASSIGN BASIC SCOUT ROLE
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
                   ZERO POINTS AGAIN

                   Normally maintenance already did this when
                   access expired. Doing it again guarantees
                   that every extension begins from zero.
                   ========================================= */

                llama_destroy_scout_points(
                    $db,
                    $scoutUserId
                );


                /* =========================================
                   COMPLIMENTARY ACCESS

                   Do not overwrite a legitimate active paid
                   Stripe membership if one still exists.
                   ========================================= */

                $membershipStatus =
                    strtolower(
                        trim(
                            (string) (
                                $freshUser[
                                    'membership_status'
                                ]
                                ?? ''
                            )
                        )
                    );


                $hasPaidMembership =
                    in_array(
                        $membershipStatus,
                        [
                            'active',
                            'trialing',
                            'past_due'
                        ],
                        true
                    )
                    &&
                    !empty(
                        $freshUser[
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
                                    ?,

                                membership_ends_at =
                                    ?

                            WHERE id = ?
                            '
                        );


                    $membershipUpdate->execute([
                        $extensionStartedAt,
                        $extensionEndsAt,
                        $scoutUserId
                    ]);
                }


                $db->commit();


                $message =
                    '30-day basic Scout extension granted. The Scout must complete three accepted Scout Reports during this extension period.';


            } catch (
                Throwable $exception
            ) {

                if (
                    $db->inTransaction()
                ) {

                    $db->rollBack();
                }


                error_log(
                    'Llama Scout Scout extension error: '
                    .
                    $exception
                        ->getMessage()
                );


                $error =
                    $exception
                        ->getMessage();
            }


        /* =================================================
           RETURN FOR CHANGES
           ================================================= */

        } elseif (
            $action
            ===
            'return'
        ) {

            $returnableStates = [
                'application_started',
                'application_submitted',
                'training',
                'pending_approval',
            ];


            if (
                $reviewNotes
                ===
                ''
            ) {

                $error =
                    'Add a note explaining what needs to be changed.';


            } else {

                try {

                    $db->beginTransaction();


                    $lockedProfile =
                        fetch_one(
                            $db,
                            '
                            SELECT
                                status

                            FROM scout_profiles

                            WHERE id = ?
                              AND user_id = ?

                            LIMIT 1

                            FOR UPDATE
                            ',
                            [
                                $scoutProfileId,
                                $scoutUserId
                            ]
                        );


                    if (
                        !in_array(
                            (string) (
                                $lockedProfile[
                                    'status'
                                ]
                                ?? ''
                            ),
                            $returnableStates,
                            true
                        )
                    ) {

                        throw new RuntimeException(
                            'This Scout is no longer in a state that can be returned for changes.'
                        );
                    }


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
                                submitted_at =
                                    NULL,

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
            $action
            ===
            'decline'
        ) {

            $declinableStates = [
                'invited',
                'application_started',
                'application_submitted',
                'training',
                'pending_approval',
            ];


            if (
                $reviewNotes
                ===
                ''
            ) {

                $error =
                    'Add a review note before declining the Scout invitation.';


            } else {

                try {

                    $db->beginTransaction();


                    $lockedProfile =
                        fetch_one(
                            $db,
                            '
                            SELECT
                                status

                            FROM scout_profiles

                            WHERE id = ?
                              AND user_id = ?

                            LIMIT 1

                            FOR UPDATE
                            ',
                            [
                                $scoutProfileId,
                                $scoutUserId
                            ]
                        );


                    if (
                        !in_array(
                            (string) (
                                $lockedProfile[
                                    'status'
                                ]
                                ?? ''
                            ),
                            $declinableStates,
                            true
                        )
                    ) {

                        throw new RuntimeException(
                            'This Scout is no longer in a state that can be declined.'
                        );
                    }


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


                    if (
                        $application
                    ) {

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
                    }


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
       RELOAD AFTER POST
       ===================================================== */

    $scout =
        load_scout(
            $db,
            $scoutProfileId
        );


    $application =
        load_application(
            $db,
            $scoutProfileId,
            $scoutUserId
        );


    $training =
        load_training(
            $db,
            $scoutProfileId,
            $scoutUserId
        );


    $currentRoleSlugs =
        load_role_slugs(
            $db,
            $scoutUserId
        );


    $latestExtension =
        load_latest_scout_extension(
            $db,
            $scoutProfileId,
            $scoutUserId
        );


    $applicationSubmitted =
        scout_application_submitted(
            $application
        );


    $trainingVersionCurrent =
        scout_training_version_current(
            $training
        );


    $trainingCompleted =
        scout_training_completed(
            $training
        );


    $allTrainingAcknowledgements =
        scout_acknowledgements_complete(
            $training
        );


    $emailVerified =
        !empty(
            $scout[
                'email_verified_at'
            ]
        );


    $isReadyForApproval =
        scout_is_ready_for_approval(
            $scout,
            $application,
            $training
        );
}


/* =========================================================
   CONTRIBUTION STATS
   ========================================================= */

$submissionStats =
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*)
                AS total,

            SUM(
                CASE
                    WHEN status =
                        \'approved\'
                    THEN 1
                    ELSE 0
                END
            )
                AS approved,

            SUM(
                CASE
                    WHEN status =
                        \'pending\'
                    THEN 1
                    ELSE 0
                END
            )
                AS pending,

            SUM(
                CASE
                    WHEN status =
                        \'needs-changes\'
                    THEN 1
                    ELSE 0
                END
            )
                AS needs_changes,

            SUM(
                CASE
                    WHEN status =
                        \'rejected\'
                    THEN 1
                    ELSE 0
                END
            )
                AS rejected

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

        ORDER BY
            submitted_at DESC

        LIMIT 8
        ',
        [
            $scoutUserId
        ]
    );


$activityStats =
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*)
                AS activity_count,

            COALESCE(
                SUM(points),
                0
            )
                AS total_points

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
   STATUS / EXTENSION STATE
   ========================================================= */

$scoutStatus =
    (string) (
        $scout[
            'status'
        ]
        ?? ''
    );


$scoutIsActive =
    $scoutStatus
    ===
    'active';


$scoutIsInOnboarding =
    in_array(
        $scoutStatus,
        [
            'invited',
            'application_started',
            'application_submitted',
            'training',
            'pending_approval'
        ],
        true
    );


$canReturnForChanges =
    in_array(
        $scoutStatus,
        [
            'application_started',
            'application_submitted',
            'training',
            'pending_approval'
        ],
        true
    );


$activeExtension =
    (
        !empty(
            $latestExtension
        )
        &&
        (
            (string) (
                $latestExtension[
                    'status'
                ]
                ?? ''
            )
            ===
            'active'
        )
    );


$canGrantExtension =
    $scoutStatus
    ===
    'inactive'
    &&
    !$activeExtension;


/* =========================================================
   CURRENT SCOUT PERIOD
   ========================================================= */

$scoutYearStart = null;
$scoutYearEnd = null;

$acceptedCurrentYear = 0;
$reportsRequired = 3;
$reportsRemaining = 3;
$requirementMet = false;
$requirementProgress = 0;

$currentPeriodIsExtension =
    false;


if (
    $scoutIsActive
    &&
    $activeExtension
) {

    $currentPeriodIsExtension =
        true;


    $scoutYearStart =
        (string) (
            $latestExtension[
                'started_at'
            ]
            ?? ''
        );


    $scoutYearEnd =
        (string) (
            $latestExtension[
                'ends_at'
            ]
            ?? ''
        );


} elseif (
    !empty(
        $scout[
            'scout_started_at'
        ]
    )
    &&
    !empty(
        $scout[
            'active_through'
        ]
    )
) {

    $yearEndTimestamp =
        strtotime(
            (string)
            $scout[
                'active_through'
            ]
        );


    $scoutStartedTimestamp =
        strtotime(
            (string)
            $scout[
                'scout_started_at'
            ]
        );


    if (
        $yearEndTimestamp
        !==
        false
        &&
        $scoutStartedTimestamp
        !==
        false
    ) {

        $yearStartTimestamp =
            strtotime(
                '-1 year',
                $yearEndTimestamp
            );


        if (
            $yearStartTimestamp
            <
            $scoutStartedTimestamp
        ) {

            $yearStartTimestamp =
                $scoutStartedTimestamp;
        }


        $scoutYearStart =
            date(
                'Y-m-d H:i:s',
                $yearStartTimestamp
            );


        $scoutYearEnd =
            date(
                'Y-m-d H:i:s',
                $yearEndTimestamp
            );
    }
}


if (
    $scoutYearStart
    &&
    $scoutYearEnd
) {

    $currentPeriodActivity =
        fetch_one(
            $db,
            '
            SELECT
                COUNT(*)
                    AS accepted_reports

            FROM scout_activity

            WHERE scout_profile_id = ?
              AND user_id = ?

              AND activity_type =
                  \'place_approved\'

              AND occurred_at >= ?
              AND occurred_at < ?
            ',
            [
                $scoutProfileId,
                $scoutUserId,
                $scoutYearStart,
                $scoutYearEnd
            ]
        );


    $acceptedCurrentYear =
        (int) (
            $currentPeriodActivity[
                'accepted_reports'
            ]
            ?? 0
        );


    $reportsRemaining =
        max(
            0,
            $reportsRequired
            -
            $acceptedCurrentYear
        );


    $requirementMet =
        $acceptedCurrentYear
        >=
        $reportsRequired;


    $requirementProgress =
        min(
            100,
            (
                min(
                    $acceptedCurrentYear,
                    $reportsRequired
                )
                /
                $reportsRequired
            )
            *
            100
        );
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


$extensionGranterName =
    trim(
        (string) (
            $latestExtension[
                'granted_by_display_name'
            ]
            ??
            $latestExtension[
                'granted_by_username'
            ]
            ??
            ''
        )
    );


$scoutMembershipStatus =
    strtolower(
        trim(
            (string) (
                $scout[
                    'membership_status'
                ]
                ?? ''
            )
        )
    );


$scoutHasStripeSubscription =
    !empty(
        $scout[
            'stripe_subscription_id'
        ]
    )
    &&
    in_array(
        $scoutMembershipStatus,
        [
            'active',
            'trialing',
            'past_due'
        ],
        true
    );


$scoutCancelScheduled =
    !empty(
        $scout[
            'stripe_cancel_at_period_end'
        ]
    );


$introEyebrow =
    $scoutIsActive
        ? (
            $currentPeriodIsExtension
                ? 'Scout Extension'
                : 'Scout Management'
        )
        : (
            $scoutIsInOnboarding
                ? 'Scout Review'
                : 'Scout Record'
        );


$introCopy =
    $scoutIsActive
        ? (
            $currentPeriodIsExtension
                ? 'Review this Scout\'s temporary 30-day reinstatement period and progress toward three accepted Scout Reports.'
                : "Review this Scout's current year, contribution progress, account, activity, and access."
        )
        : (
            $scoutIsInOnboarding
                ? "Review the candidate's account, contributions, About You responses, training completion, and Scout agreements before activating Scout access."
                : 'Review this Scout record, account history, contributions, and previous onboarding information.'
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
    <?= e($displayName) ?> | Scout | Llama Scout Basecamp
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

    .scout-review-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 330px;
      gap: 22px;
      margin-top: 22px;
    }


    .scout-review-main,
    .scout-review-side {
      display: grid;
      gap: 18px;
      align-content: start;
    }


    .review-answer {
      padding: 16px 0;
      border-top: 1px solid rgba(23, 40, 34, .09);
    }


    .review-answer:first-of-type {
      border-top: 0;
    }


    .review-answer strong {
      display: block;
      margin-bottom: 6px;
    }


    .review-answer p {
      margin: 0;
      white-space: pre-wrap;
      line-height: 1.65;
    }


    .review-answer-empty {
      opacity: .55;
      font-style: italic;
    }


    .review-facts {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }


    .review-fact {
      padding: 14px;
      border-radius: 11px;
      background: rgba(23, 40, 34, .055);
    }


    .review-fact span {
      display: block;
      margin-bottom: 4px;
      font-size: .76rem;
      opacity: .65;
    }


    .review-fact strong {
      display: block;
    }


    .review-checklist {
      display: grid;
      gap: 10px;
    }


    .review-check {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 12px;
      border-radius: 10px;
      background: rgba(23, 40, 34, .05);
    }


    .review-check.good i {
      color: #267447;
    }


    .review-check.bad i {
      color: #9b3434;
    }


    .scout-year-progress {
      margin-top: 17px;
    }


    .scout-year-progress-top {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
      font-weight: 750;
    }


    .scout-year-track {
      overflow: hidden;
      height: 10px;
      border-radius: 999px;
      background: rgba(23, 40, 34, .09);
    }


    .scout-year-fill {
      height: 100%;
      border-radius: inherit;
      background: #172822;
    }


    .scout-year-result {
      margin-top: 11px;
      padding: 11px 12px;
      border-radius: 10px;
      background: rgba(23, 40, 34, .055);
      line-height: 1.5;
    }


    .scout-year-result.met {
      background: rgba(31, 122, 72, .11);
    }


    .review-submission {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      padding: 12px 0;
      border-top: 1px solid rgba(23, 40, 34, .09);
    }


    .review-submission:first-of-type {
      border-top: 0;
    }


    .review-submission-name {
      font-weight: 700;
    }


    .review-submission-meta {
      margin-top: 3px;
      font-size: .82rem;
      opacity: .67;
    }


    .review-role-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }


    .review-role {
      padding: 7px 10px;
      border-radius: 999px;
      background: rgba(23, 40, 34, .07);
      font-size: .82rem;
      font-weight: 700;
    }


    .review-actions textarea {
      width: 100%;
      box-sizing: border-box;
      min-height: 130px;
      padding: 12px;
      border: 1px solid rgba(23, 40, 34, .18);
      border-radius: 10px;
      font: inherit;
      line-height: 1.55;
      resize: vertical;
    }


    .review-action-buttons {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }


    .review-action-button {
      width: 100%;
      min-height: 44px;
      padding: 10px 14px;
      border: 0;
      border-radius: 9px;
      font: inherit;
      font-weight: 750;
      cursor: pointer;
    }


    .review-action-button.approve {
      background: #172822;
      color: #fff;
    }


    .review-action-button.return {
      background: #e7dcc4;
      color: #392e1c;
    }


    .review-action-button.decline {
      background: #8c3232;
      color: #fff;
    }


    .review-action-button.extension {
      background: #172822;
      color: #fff;
    }


    .review-action-button:disabled {
      opacity: .45;
      cursor: not-allowed;
    }


    .review-private {
      display: flex;
      gap: 9px;
      margin-bottom: 15px;
      padding: 12px;
      border-radius: 10px;
      background: rgba(23, 40, 34, .055);
      font-size: .85rem;
      line-height: 1.45;
    }


    .extension-warning {
      margin-top: 14px;
      padding: 13px;
      border-radius: 10px;
      background: rgba(217, 196, 154, .2);
      line-height: 1.55;
    }


    @media (
      max-width:
        860px
    ) {

      .scout-review-grid {
        grid-template-columns: 1fr;
      }

    }


    @media (
      max-width:
        560px
    ) {

      .review-facts {
        grid-template-columns: 1fr;
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


<main class="admin-main">


  <a
    class="admin-button"
    href="/scouts.php"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to Llama Scouts

  </a>


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
  <?= e($displayName) ?>
</h1>

<p>
  <?= e($introEyebrow) ?>
  &middot;
  <?= e($introCopy) ?>
</p>

      </div>


      <div>

        <span
          class="
            admin-badge
            <?= e(
                scout_status_badge_class(
                    $scoutStatus
                )
            ) ?>
          "
        >

          <i
            class="fa-solid fa-compass"
            aria-hidden="true"
          ></i>

          <?= e(
              scout_status_label(
                  $scoutStatus
              )
          ) ?>

        </span>

      </div>

    </div>

  </section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <?php if ($message): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >
      <?= e($message) ?>
    </div>

  <?php endif; ?>


  <?php if ($error): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >
      <?= e($error) ?>
    </div>

  <?php endif; ?>


  <div class="scout-review-grid">


    <div class="scout-review-main">


      <?php if ($scoutIsActive): ?>


        <section class="admin-card">

          <h2>

            <?= $currentPeriodIsExtension
                ? '30-Day Scout Extension'
                : 'Current Scout Year'
            ?>

          </h2>


          <p>

            <?php if ($currentPeriodIsExtension): ?>

              This Scout has temporary basic Scout access for
              30 days. Three newly accepted Scout Reports are
              required during this exact extension period.

            <?php else: ?>

              Scout access renews for one additional year when
              at least three Scout Reports are accepted during
              this fixed Scout year.

            <?php endif; ?>

          </p>


          <div class="review-facts">


            <div class="review-fact">

              <span>

                <?= $currentPeriodIsExtension
                    ? 'Extension Begins'
                    : 'Scout Year Begins'
                ?>

              </span>

              <strong>
                <?= e(
                    format_admin_date(
                        $scoutYearStart
                    )
                ) ?>
              </strong>

            </div>


            <div class="review-fact">

              <span>

                <?= $currentPeriodIsExtension
                    ? 'Extension Ends'
                    : 'Scout Year Ends'
                ?>

              </span>

              <strong>
                <?= e(
                    format_admin_date(
                        $scoutYearEnd
                    )
                ) ?>
              </strong>

            </div>


            <div class="review-fact">

              <span>
                Accepted This Period
              </span>

              <strong>
                <?= $acceptedCurrentYear ?>
              </strong>

            </div>


            <div class="review-fact">

              <span>
                Reports Still Needed
              </span>

              <strong>
                <?= $reportsRemaining ?>
              </strong>

            </div>


          </div>


          <div class="scout-year-progress">

            <div class="scout-year-progress-top">

              <span>

                <?= $currentPeriodIsExtension
                    ? 'Extension Requirement'
                    : 'Annual Requirement'
                ?>

              </span>

              <span>
                <?= $acceptedCurrentYear ?>/3
              </span>

            </div>


            <div class="scout-year-track">

              <div
                class="scout-year-fill"
                style="
                  width:
                  <?= number_format(
                      $requirementProgress,
                      2,
                      '.',
                      ''
                  ) ?>%;
                "
              ></div>

            </div>


            <div
              class="
                scout-year-result
                <?= $requirementMet
                    ? 'met'
                    : ''
                ?>
              "
            >

              <?php if ($requirementMet): ?>

                <strong>
                  Requirement met.
                </strong>

                <?php if ($currentPeriodIsExtension): ?>

                  This Scout has completed the three accepted
                  reports required during the extension. When
                  the extension closes, they return as a basic
                  Scout for a new annual Scout period.

                <?php else: ?>

                  This Scout has completed the minimum required
                  reports for this Scout year.

                  <?php if (
                      $acceptedCurrentYear
                      >
                      3
                  ): ?>

                    They have completed
                    <?= $acceptedCurrentYear ?>
                    accepted reports, but additional reports do
                    not stack additional membership years.

                  <?php endif; ?>

                <?php endif; ?>

              <?php else: ?>

                <strong>

                  <?= $reportsRemaining ?>
                  more accepted

                  <?= $reportsRemaining === 1
                      ? 'report is'
                      : 'reports are'
                  ?>

                  required.

                </strong>

                <?php if ($currentPeriodIsExtension): ?>

                  If the requirement is not completed before
                  this extension ends, Scout access expires
                  again and the account returns to free status.

                <?php else: ?>

                  The requirement must be completed before the
                  Scout year ends.

                <?php endif; ?>

              <?php endif; ?>

            </div>

          </div>

        </section>


      <?php endif; ?>


      <section class="admin-card">

        <h2>
          About You
        </h2>

        <p>

          <?= $scoutIsInOnboarding
              ? 'What the candidate shared during Scout onboarding.'
              : 'Information shared during Scout onboarding.'
          ?>

        </p>


        <?php if ($application): ?>


          <?php

          $answers = [

              'Why are they interested in becoming a Scout?'
                  =>
                  $application[
                      'why_scout'
                  ]
                  ?? '',

              'What does travel usually look like for them?'
                  =>
                  $application[
                      'travel_experience'
                  ]
                  ?? '',

              'What kinds of things do they naturally notice?'
                  =>
                  $application[
                      'field_experience'
                  ]
                  ?? '',

              'Accessibility perspective'
                  =>
                  $application[
                      'accessibility_experience'
                  ]
                  ?? '',

              'Sensory perspective'
                  =>
                  $application[
                      'sensory_experience'
                  ]
                  ?? '',
          ];

          ?>


          <?php foreach (
              $answers
              as
              $question
              =>
              $answer
          ): ?>

            <div class="review-answer">

              <strong>
                <?= e($question) ?>
              </strong>

              <?php if (
                  trim(
                      (string)
                      $answer
                  )
                  !==
                  ''
              ): ?>

                <p>
                  <?= e($answer) ?>
                </p>

              <?php else: ?>

                <p class="review-answer-empty">
                  No answer provided.
                </p>

              <?php endif; ?>

            </div>

          <?php endforeach; ?>


        <?php else: ?>

          <p class="review-answer-empty">
            No About You information has been submitted.
          </p>

        <?php endif; ?>

      </section>


      <section class="admin-card">

        <h2>
          Community Contributions
        </h2>

        <p>

          Submission history and Scout activity associated
          with this account.

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
              Lifetime approved
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
              Scout activity
            </span>

            <strong>

              <?= (int) (
                  $activityStats[
                      'activity_count'
                  ]
                  ?? 0
              ) ?>

            </strong>

          </div>


          <div class="review-fact">

            <span>
              Scout points
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


          <?php if ($scoutIsActive): ?>

            <div class="review-fact">

              <span>

                <?= $currentPeriodIsExtension
                    ? 'Accepted this extension'
                    : 'Accepted this Scout year'
                ?>

              </span>

              <strong>
                <?= $acceptedCurrentYear ?>
              </strong>

            </div>

          <?php endif; ?>


        </div>


        <?php if ($recentSubmissions): ?>

          <div
            style="
              margin-top:
                18px;
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


      <section class="admin-card">

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
                    ?? ''
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
                    ?:
                    'Not provided'
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
                    ?? ''
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
                    ?? ''
                ) ?>

                <?php if (
                    !empty(
                        $application[
                            'state_region'
                        ]
                    )
                ): ?>

                  ,

                  <?= e(
                      $application[
                          'state_region'
                      ]
                  ) ?>

                <?php endif; ?>


                <?= e(
                    $application[
                        'postal_code'
                    ]
                    ?? ''
                ) ?>


                <?php if (
                    !empty(
                        $application[
                            'country'
                        ]
                    )
                ): ?>

                  <br>

                  <?= e(
                      $application[
                          'country'
                      ]
                  ) ?>

                <?php endif; ?>

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


    <aside class="scout-review-side">


      <?php if ($scoutIsInOnboarding): ?>


        <section class="admin-card">

          <h2>
            Onboarding Check
          </h2>

          <p>
            Everything required before Scout activation.
          </p>


          <div class="review-checklist">


            <div
              class="
                review-check
                <?= $applicationSubmitted
                    ? 'good'
                    : 'bad'
                ?>
              "
            >

              <i
                class="
                  <?= $applicationSubmitted
                      ? 'fa-solid fa-circle-check'
                      : 'fa-solid fa-circle-xmark'
                  ?>
                "
                aria-hidden="true"
              ></i>

              <span>
                About You completed
              </span>

            </div>


            <div
              class="
                review-check
                <?= $trainingVersionCurrent
                    ? 'good'
                    : 'bad'
                ?>
              "
            >

              <i
                class="
                  <?= $trainingVersionCurrent
                      ? 'fa-solid fa-circle-check'
                      : 'fa-solid fa-circle-xmark'
                  ?>
                "
                aria-hidden="true"
              ></i>

              <span>
                Current training version completed
              </span>

            </div>


            <div
              class="
                review-check
                <?= $trainingCompleted
                    ? 'good'
                    : 'bad'
                ?>
              "
            >

              <i
                class="
                  <?= $trainingCompleted
                      ? 'fa-solid fa-circle-check'
                      : 'fa-solid fa-circle-xmark'
                  ?>
                "
                aria-hidden="true"
              ></i>

              <span>
                Training video completed
              </span>

            </div>


            <div
              class="
                review-check
                <?= $allTrainingAcknowledgements
                    ? 'good'
                    : 'bad'
                ?>
              "
            >

              <i
                class="
                  <?= $allTrainingAcknowledgements
                      ? 'fa-solid fa-circle-check'
                      : 'fa-solid fa-circle-xmark'
                  ?>
                "
                aria-hidden="true"
              ></i>

              <span>
                Training acknowledgements complete
              </span>

            </div>


            <div
              class="
                review-check
                <?= $emailVerified
                    ? 'good'
                    : 'bad'
                ?>
              "
            >

              <i
                class="
                  <?= $emailVerified
                      ? 'fa-solid fa-circle-check'
                      : 'fa-solid fa-circle-xmark'
                  ?>
                "
                aria-hidden="true"
              ></i>

              <span>
                Email verified
              </span>

            </div>


          </div>

        </section>


      <?php endif; ?>


      <section class="admin-card">

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
                ?:
                'None'
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
                            ?:
                            'none'
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


      <section class="admin-card">

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
                $inviterName
                !==
                ''
            ): ?>

              by
              <?= e($inviterName) ?>

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
                  $approverName
                  !==
                  ''
              ): ?>

                by
                <?= e($approverName) ?>

              <?php endif; ?>

            </p>

          </div>

        <?php endif; ?>


        <?php if (
            !empty(
                $scout[
                    'scout_started_at'
                ]
            )
        ): ?>

          <div class="review-answer">

            <strong>
              Original Scout start
            </strong>

            <p>

              <?= e(
                  format_admin_date(
                      $scout[
                          'scout_started_at'
                      ]
                  )
              ) ?>

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


        <?php if ($latestExtension): ?>

          <div class="review-answer">

            <strong>
              Latest Scout extension
            </strong>

            <p>

              <?= e(
                  scout_extension_status_label(
                      (string) (
                          $latestExtension[
                              'status'
                          ]
                          ?? ''
                      )
                  )
              ) ?>

              <br>

              <?= e(
                  format_admin_date(
                      $latestExtension[
                          'started_at'
                      ]
                      ?? null
                  )
              ) ?>

              to

              <?= e(
                  format_admin_date(
                      $latestExtension[
                          'ends_at'
                      ]
                      ?? null
                  )
              ) ?>

              <?php if (
                  $extensionGranterName
                  !==
                  ''
              ): ?>

                <br>
                Granted by
                <?= e($extensionGranterName) ?>

              <?php endif; ?>

            </p>

          </div>

        <?php endif; ?>


      </section>


      <?php if ($scoutIsInOnboarding): ?>


        <section
          class="
            admin-card
            review-actions
          "
        >

          <h2>
            Review
          </h2>

          <p>

            Add an internal or candidate-facing review note
            if needed, then choose an action.

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


              <?php if ($canReturnForChanges): ?>

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

              <?php endif; ?>


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


      <?php elseif ($scoutIsActive): ?>


        <section class="admin-card">

          <h2>

            <?= $currentPeriodIsExtension
                ? 'Scout Extension Active'
                : 'Scout Active'
            ?>

          </h2>

          <p>

            <?php if ($currentPeriodIsExtension): ?>

              This account currently has temporary basic
              Scout access through a 30-day extension.

            <?php else: ?>

              This account has completed onboarding and
              currently has active Scout status.

            <?php endif; ?>

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


          <?php if (
              $currentPeriodIsExtension
          ): ?>

            <div class="extension-warning">

              This extension does not restore a previous
              Master Scout rank. The Scout must complete
              three accepted Scout Reports during the
              extension period.

            </div>

          <?php endif; ?>


          <?php if (
              $scoutHasStripeSubscription
              &&
              !$scoutCancelScheduled
          ): ?>


            <div
              style="
                margin-top: 18px;
                padding: 15px;
                border-radius: 11px;
                background: rgba(217, 196, 154, .18);
              "
            >

              <strong>
                Paid membership still renewing
              </strong>


              <p
                style="
                  margin: 7px 0 14px;
                  line-height: 1.55;
                "
              >

                This Scout still has a paid Stripe
                subscription that is currently set to renew.
                Scout access is already active, so the paid
                subscription can be scheduled to end after
                the current paid billing period.

              </p>


              <form
                method="post"
                action="/scout-billing.php"
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


                <button
                  class="
                    review-action-button
                    approve
                  "
                  type="submit"
                  onclick="
                    return confirm(
                      'Schedule this Scout paid membership to stop renewing at the end of the current billing period? Scout access will remain active.'
                    );
                  "
                >

                  <i
                    class="fa-solid fa-calendar-xmark"
                    aria-hidden="true"
                  ></i>

                  Stop Paid Membership Renewal

                </button>


              </form>

            </div>


          <?php elseif (
              $scoutHasStripeSubscription
              &&
              $scoutCancelScheduled
          ): ?>


            <div
              class="
                review-check
                good
              "
              style="
                margin-top:
                  12px;
              "
            >

              <i
                class="fa-solid fa-calendar-check"
                aria-hidden="true"
              ></i>

              <span>
                Paid membership is scheduled not to renew
              </span>

            </div>


          <?php endif; ?>


        </section>


      <?php else: ?>


        <section class="admin-card">

          <h2>
            <?= e(
                scout_status_label(
                    $scoutStatus
                )
            ) ?>
          </h2>


          <p>

            This Scout record is not currently active and is
            not in the onboarding workflow.

          </p>


          <div class="review-check bad">

            <i
              class="fa-solid fa-circle-info"
              aria-hidden="true"
            ></i>

            <span>
              Scout access is not active.
            </span>

          </div>


          <?php if ($canGrantExtension): ?>

            <div class="extension-warning">

              An Admin or Owner may grant this former Scout
              exactly 30 days of temporary basic Scout
              access. They will begin at zero points and
              must complete three newly accepted Scout
              Reports during that extension.

            </div>


            <form
              method="post"
              action="scout.php"
              style="
                margin-top:
                  14px;
              "
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


              <button
                class="
                  review-action-button
                  extension
                "
                type="submit"
                name="action"
                value="grant_extension"
                onclick="
                  return confirm(
                    'Grant this former Scout a 30-day basic Scout extension? Any former Master Scout rank will not be restored, points remain at zero, and they must complete three accepted Scout Reports during the extension.'
                  );
                "
              >

                <i
                  class="fa-solid fa-clock-rotate-left"
                  aria-hidden="true"
                ></i>

                Grant 30-Day Scout Extension

              </button>


            </form>


          <?php endif; ?>


        </section>


      <?php endif; ?>


    </aside>


  </div>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
