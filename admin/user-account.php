<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   ADMIN USER ACCOUNT
   admin/user-account.php

   Account editing + email/password assistance + owner-only
   account cleanup in one place.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/mail.php';

require_once
    dirname(__DIR__)
    . '/app/username-policy.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';

require_once
    dirname(__DIR__)
    . '/app/memberships.php';


require_role(
    'admin'
);


start_llama_session();


$adminUser =
    current_user();


if (!$adminUser) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );
}


$db =
    db();


/* =========================================================
   CURRENT ADMIN AUTHORITY
   ========================================================= */


$currentAdminId =
    (int)
    $adminUser['id'];


$currentAdminIsOwner =
    user_is_owner(
        $currentAdminId
    );


$primaryRoleLabel =
    llama_primary_role_label(
        $currentAdminId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $currentAdminId
    );


/* =========================================================
   GENERAL HELPERS
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


function fetch_user(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                email,
                username,
                display_name,
                timezone,
                status,
                email_verified_at,
                created_at,
                last_login_at,
                dormancy_notice_sent_at,

                stripe_customer_id,
                stripe_subscription_id,
                stripe_cancel_at_period_end,

                membership_status,
                membership_interval,
                membership_started_at,
                membership_ends_at

            FROM users

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?: [];
}


function fetch_role_slugs(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT
                r.slug

            FROM roles r

            INNER JOIN user_roles ur
              ON ur.role_id = r.id

            WHERE ur.user_id = ?

            ORDER BY r.slug
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        array_column(
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ),
            'slug'
        );
}


/* =========================================================
   CREATE EMAIL VERIFICATION
   ========================================================= */


function create_verification(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();


    try {

        $stmt =
            $db->prepare(
                '
                UPDATE email_verifications

                SET used_at =
                    CURRENT_TIMESTAMP

                WHERE user_id = ?
                  AND used_at IS NULL
                '
            );


        $stmt->execute([
            $user['id']
        ]);


        $token =
            bin2hex(
                random_bytes(
                    32
                )
            );


        $tokenHash =
            hash(
                'sha256',
                $token
            );


        $stmt =
            $db->prepare(
                '
                INSERT INTO email_verifications
                (
                    user_id,
                    token_hash,
                    expires_at
                )

                VALUES
                (
                    ?,
                    ?,
                    DATE_ADD(
                        CURRENT_TIMESTAMP,
                        INTERVAL 24 HOUR
                    )
                )
                '
            );


        $stmt->execute([
            $user['id'],
            $tokenHash
        ]);


        $db->commit();


        return
            send_verification_email(
                $user,
                $token
            );


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }
}


/* =========================================================
   CREATE PASSWORD RESET
   ========================================================= */


function create_password_reset(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();


    try {

        $expireStmt =
            $db->prepare(
                '
                UPDATE password_resets

                SET used_at =
                    CURRENT_TIMESTAMP

                WHERE user_id = ?
                  AND used_at IS NULL
                '
            );


        $expireStmt->execute([
            $user['id']
        ]);


        $token =
            bin2hex(
                random_bytes(
                    32
                )
            );


        $tokenHash =
            hash(
                'sha256',
                $token
            );


        $insertStmt =
            $db->prepare(
                '
                INSERT INTO password_resets
                (
                    user_id,
                    token_hash,
                    expires_at
                )

                VALUES
                (
                    ?,
                    ?,
                    DATE_ADD(
                        CURRENT_TIMESTAMP,
                        INTERVAL 60 MINUTE
                    )
                )
                '
            );


        $insertStmt->execute([
            $user['id'],
            $tokenHash
        ]);


        $db->commit();


        $resetUrl =
            'https://account.llamascout.com/reset-password.php?token='
            .
            rawurlencode(
                $token
            );


        $name =
            trim(
                (string) (
                    $user[
                        'display_name'
                    ]
                    ?: $user[
                        'username'
                    ]
                    ?: 'Scout'
                )
            );


        $subject =
            'Reset your Llama Scout password';


        $mailMessage =
            "Hi {$name},\n\n"
            .
            "Llama Scout support sent you a secure password reset link.\n\n"
            .
            "Use the link below to choose a new password:\n\n"
            .
            $resetUrl
            .
            "\n\n"
            .
            "This link expires in 60 minutes and can only be used once.\n\n"
            .
            "If you were not expecting this email, you can ignore it.\n\n"
            .
            "Llama Scout";


        return
            send_llama_mail(
                $user[
                    'email'
                ],
                $subject,
                $mailMessage
            );


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }
}


/* =========================================================
   ACCOUNT CLEANUP HELPERS
   ========================================================= */


function admin_cleanup_table_exists(
    PDO $db,
    string $table
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM information_schema.tables

            WHERE table_schema = DATABASE()
              AND table_name = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $table
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


function admin_cleanup_column_exists(
    PDO $db,
    string $table,
    string $column
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM information_schema.columns

            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $table,
        $column,
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


function admin_cleanup_delete_by_user_id(
    PDO $db,
    string $table,
    int $userId
): int {

    if (
        !preg_match(
            '/^[a-zA-Z0-9_]+$/',
            $table
        )
    ) {

        throw new RuntimeException(
            'Unsafe table identifier.'
        );
    }


    if (
        !admin_cleanup_table_exists(
            $db,
            $table
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            $table,
            'user_id'
        )
    ) {

        return 0;
    }


    $stmt =
        $db->prepare(
            'DELETE FROM `'
            .
            $table
            .
            '` WHERE user_id = ?'
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->rowCount();
}


function admin_cleanup_has_scout_profile(
    PDO $db,
    int $userId
): bool {

    if (
        !admin_cleanup_table_exists(
            $db,
            'scout_profiles'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'scout_profiles',
            'user_id'
        )
    ) {

        return false;
    }


    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM scout_profiles

            WHERE user_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


function admin_cleanup_published_place_count(
    PDO $db,
    int $userId
): int {

    if (
        !admin_cleanup_table_exists(
            $db,
            'place_submissions'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'place_submissions',
            'user_id'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'place_submissions',
            'place_id'
        )
    ) {

        return 0;
    }


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM place_submissions

            WHERE user_id = ?
              AND place_id IS NOT NULL
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (int)
        $stmt->fetchColumn();
}


function admin_cleanup_approved_update_count(
    PDO $db,
    int $userId
): int {

    if (
        !admin_cleanup_table_exists(
            $db,
            'place_update_submissions'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'place_update_submissions',
            'user_id'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'place_update_submissions',
            'status'
        )
    ) {

        return 0;
    }


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM place_update_submissions

            WHERE user_id = ?
              AND status = \'approved\'
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (int)
        $stmt->fetchColumn();
}


function admin_cleanup_deleted_username(
    int $userId
): string {

    return
        'user_'
        .
        str_pad(
            (string) $userId,
            4,
            '0',
            STR_PAD_LEFT
        );
}


function admin_cleanup_deleted_email(
    int $userId
): string {

    return
        'deleted+'
        .
        str_pad(
            (string) $userId,
            4,
            '0',
            STR_PAD_LEFT
        )
        .
        '@llamascout.invalid';
}


function admin_cleanup_random_password_hash(): string {

    $hash =
        password_hash(
            bin2hex(
                random_bytes(
                    64
                )
            ),
            PASSWORD_DEFAULT
        );


    if (
        !is_string(
            $hash
        )
        ||
        $hash === ''
    ) {

        throw new RuntimeException(
            'Unable to replace account credentials.'
        );
    }


    return
        $hash;
}


function admin_cleanup_assign_member_role(
    PDO $db,
    int $userId
): void {

    admin_cleanup_delete_by_user_id(
        $db,
        'user_roles',
        $userId
    );


    $stmt =
        $db->prepare(
            '
            SELECT id

            FROM roles

            WHERE slug = \'member\'

            LIMIT 1
            '
        );


    $stmt->execute();


    $roleId =
        (int)
        $stmt->fetchColumn();


    if (
        $roleId < 1
    ) {

        throw new RuntimeException(
            'The Member role is missing.'
        );
    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO user_roles
            (
                user_id,
                role_id
            )

            VALUES
            (
                ?,
                ?
            )
            '
        );


    $stmt->execute([
        $userId,
        $roleId,
    ]);
}


function admin_cleanup_stripe_is_test_mode(): bool {

    try {

        $config =
            llama_stripe_config();


        $secret =
            trim(
                (string) (
                    $config[
                        'secret_key'
                    ]
                    ?? ''
                )
            );


        return
            str_starts_with(
                $secret,
                'sk_test_'
            );


    } catch (
        Throwable
    ) {

        return false;
    }
}


function admin_cleanup_stripe_test_customer(
    ?string $subscriptionId,
    ?string $customerId
): array {

    $subscriptionId =
        trim(
            (string)
            $subscriptionId
        );


    $customerId =
        trim(
            (string)
            $customerId
        );


    if (
        $subscriptionId === ''
        &&
        $customerId === ''
    ) {

        return [
            'subscription_canceled' =>
                false,

            'customer_deleted' =>
                false,
        ];
    }


    if (
        !admin_cleanup_stripe_is_test_mode()
    ) {

        throw new RuntimeException(
            'This account contains live Stripe billing data. Resolve live billing before using administrative Account Cleanup.'
        );
    }


    $stripe =
        llama_stripe_client();


    $subscriptionCanceled =
        false;


    $customerDeleted =
        false;


    if (
        $subscriptionId !== ''
    ) {

        try {

            $stripe
                ->subscriptions
                ->cancel(
                    $subscriptionId,
                    []
                );


            $subscriptionCanceled =
                true;


        } catch (
            Throwable $exception
        ) {

            $text =
                strtolower(
                    $exception
                        ->getMessage()
                );


            if (
                !str_contains(
                    $text,
                    'no such subscription'
                )
                &&
                !str_contains(
                    $text,
                    'resource_missing'
                )
                &&
                !str_contains(
                    $text,
                    'already canceled'
                )
            ) {

                throw
                    $exception;
            }
        }
    }


    if (
        $customerId !== ''
    ) {

        try {

            $stripe
                ->customers
                ->delete(
                    $customerId,
                    []
                );


            $customerDeleted =
                true;


        } catch (
            Throwable $exception
        ) {

            $text =
                strtolower(
                    $exception
                        ->getMessage()
                );


            if (
                !str_contains(
                    $text,
                    'no such customer'
                )
                &&
                !str_contains(
                    $text,
                    'resource_missing'
                )
            ) {

                throw
                    $exception;
            }
        }
    }


    return [
        'subscription_canceled' =>
            $subscriptionCanceled,

        'customer_deleted' =>
            $customerDeleted,
    ];
}


function admin_cleanup_reset_membership_columns(
    PDO $db,
    int $userId
): void {

    $values = [
        'stripe_customer_id' => null,
        'stripe_subscription_id' => null,
        'stripe_cancel_at_period_end' => 0,
        'membership_status' => 'none',
        'membership_interval' => null,
        'membership_started_at' => null,
        'membership_ends_at' => null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            admin_cleanup_column_exists(
                $db,
                'users',
                $column
            )
        ) {

            $assignments[] =
                '`'
                .
                $column
                .
                '` = ?';


            $params[] =
                $value;
        }
    }


    if (!$assignments) {

        return;
    }


    $params[] =
        $userId;


    $stmt =
        $db->prepare(
            '
            UPDATE users

            SET
                '
                .
                implode(
                    ",
                ",
                    $assignments
                )
                .
            '

            WHERE id = ?
            '
        );


    $stmt->execute(
        $params
    );
}


function admin_cleanup_delete_unpublished_content(
    PDO $db,
    int $userId
): void {

    if (
        admin_cleanup_table_exists(
            $db,
            'place_submissions'
        )
        &&
        admin_cleanup_column_exists(
            $db,
            'place_submissions',
            'user_id'
        )
        &&
        admin_cleanup_column_exists(
            $db,
            'place_submissions',
            'place_id'
        )
    ) {

        $stmt =
            $db->prepare(
                '
                DELETE FROM place_submissions

                WHERE user_id = ?
                  AND place_id IS NULL
                '
            );


        $stmt->execute([
            $userId
        ]);
    }


    if (
        admin_cleanup_table_exists(
            $db,
            'place_update_submissions'
        )
        &&
        admin_cleanup_column_exists(
            $db,
            'place_update_submissions',
            'user_id'
        )
        &&
        admin_cleanup_column_exists(
            $db,
            'place_update_submissions',
            'status'
        )
    ) {

        $stmt =
            $db->prepare(
                '
                DELETE FROM place_update_submissions

                WHERE user_id = ?
                  AND (
                      status IS NULL
                      OR status <> \'approved\'
                  )
                '
            );


        $stmt->execute([
            $userId
        ]);
    }
}


function admin_cleanup_remove_profile_files(
    PDO $db,
    int $userId
): void {

    if (
        !admin_cleanup_table_exists(
            $db,
            'community_profile_images'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'community_profile_images',
            'user_id'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'community_profile_images',
            'image_src'
        )
    ) {

        return;
    }


    $stmt =
        $db->prepare(
            '
            SELECT image_src

            FROM community_profile_images

            WHERE user_id = ?
            '
        );


    $stmt->execute([
        $userId
    ]);


    $documentRoot =
        realpath(
            dirname(__DIR__)
        );


    foreach (
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        )
        as
        $imageSrc
    ) {

        if (
            !is_string(
                $imageSrc
            )
            ||
            trim(
                $imageSrc
            ) === ''
            ||
            !is_string(
                $documentRoot
            )
        ) {

            continue;
        }


        $path =
            parse_url(
                $imageSrc,
                PHP_URL_PATH
            );


        if (
            !is_string(
                $path
            )
            ||
            !str_starts_with(
                $path,
                '/uploads/'
            )
        ) {

            continue;
        }


        $candidate =
            dirname(__DIR__)
            .
            '/'
            .
            ltrim(
                $path,
                '/'
            );


        $realCandidate =
            realpath(
                $candidate
            );


        if (
            $realCandidate !== false
            &&
            str_starts_with(
                $realCandidate,
                $documentRoot
                .
                DIRECTORY_SEPARATOR
                .
                'uploads'
                .
                DIRECTORY_SEPARATOR
            )
            &&
            is_file(
                $realCandidate
            )
        ) {

            @unlink(
                $realCandidate
            );
        }
    }
}


function admin_cleanup_reset_community_profile(
    PDO $db,
    int $userId
): void {

    admin_cleanup_remove_profile_files(
        $db,
        $userId
    );


    admin_cleanup_delete_by_user_id(
        $db,
        'community_profile_images',
        $userId
    );


    if (
        !admin_cleanup_table_exists(
            $db,
            'community_profiles'
        )
        ||
        !admin_cleanup_column_exists(
            $db,
            'community_profiles',
            'user_id'
        )
    ) {

        return;
    }


    $values = [
        'is_public' => 0,
        'bio' => null,
        'location' => null,
        'squad' => null,
        'website_url' => null,
        'instagram_url' => null,
        'facebook_url' => null,
        'bluesky_url' => null,
        'youtube_url' => null,
        'tiktok_url' => null,
        'other_social_url' => null,
        'camping_style' => null,
        'favorite_places' => null,
        'favorite_camping_music' => null,
        'primary_image_id' => null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            admin_cleanup_column_exists(
                $db,
                'community_profiles',
                $column
            )
        ) {

            $assignments[] =
                '`'
                .
                $column
                .
                '` = ?';


            $params[] =
                $value;
        }
    }


    if (!$assignments) {

        return;
    }


    $params[] =
        $userId;


    $stmt =
        $db->prepare(
            '
            UPDATE community_profiles

            SET
                '
                .
                implode(
                    ",
                ",
                    $assignments
                )
                .
            '

            WHERE user_id = ?
            '
        );


    $stmt->execute(
        $params
    );
}


function admin_cleanup_anonymize_user(
    PDO $db,
    int $userId
): string {

    $anonymousUsername =
        admin_cleanup_deleted_username(
            $userId
        );


    $values = [
        'email' =>
            admin_cleanup_deleted_email(
                $userId
            ),

        'username' =>
            $anonymousUsername,

        'display_name' =>
            'Deleted User',

        'password_hash' =>
            admin_cleanup_random_password_hash(),

        'timezone' =>
            'America/Denver',

        'status' =>
            'disabled',

        'email_verified_at' =>
            null,

        'last_login_at' =>
            null,

        'dormancy_notice_sent_at' =>
            null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            admin_cleanup_column_exists(
                $db,
                'users',
                $column
            )
        ) {

            $assignments[] =
                '`'
                .
                $column
                .
                '` = ?';


            $params[] =
                $value;
        }
    }


    $params[] =
        $userId;


    $stmt =
        $db->prepare(
            '
            UPDATE users

            SET
                '
                .
                implode(
                    ",
                ",
                    $assignments
                )
                .
            '

            WHERE id = ?
            '
        );


    $stmt->execute(
        $params
    );


    return
        $anonymousUsername;
}


/* =========================================================
   USER ID
   ========================================================= */


$userId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'user_id'
        ]
        ??
        0
    );


if (
    $userId < 1
) {

    http_response_code(
        400
    );


    exit(
        'A valid user ID is required.'
    );
}


/* =========================================================
   LOAD TARGET USER
   ========================================================= */


$managedUser =
    fetch_user(
        $db,
        $userId
    );


if (
    !$managedUser
) {

    http_response_code(
        404
    );


    exit(
        'User not found.'
    );
}


/* =========================================================
   TARGET AUTHORITY
   ========================================================= */


$managedRoleSlugs =
    fetch_role_slugs(
        $db,
        $userId
    );


$managedUserIsOwner =
    in_array(
        'owner',
        $managedRoleSlugs,
        true
    );


$managedUserIsAdmin =
    in_array(
        'admin',
        $managedRoleSlugs,
        true
    );


if (
    $managedUserIsOwner
) {

    http_response_code(
        403
    );


    exit(
        'Owner accounts are protected and cannot be edited through Basecamp.'
    );
}


if (
    $managedUserIsAdmin
    &&
    !$currentAdminIsOwner
) {

    http_response_code(
        403
    );


    exit(
        'Administrator accounts are managed by a Llama Scout Owner.'
    );
}


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'admin_user_account_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_user_account_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'admin_user_account_csrf'
    ];


/* =========================================================
   POST ACTIONS
   ========================================================= */


$message =
    '';


$error =
    '';


$deleted =
    false;


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    $managedRoleSlugs =
        fetch_role_slugs(
            $db,
            $userId
        );


    $managedUserIsOwner =
        in_array(
            'owner',
            $managedRoleSlugs,
            true
        );


    $managedUserIsAdmin =
        in_array(
            'admin',
            $managedRoleSlugs,
            true
        );


    if (
        $managedUserIsOwner
    ) {

        http_response_code(
            403
        );


        exit(
            'Owner accounts are protected and cannot be edited through Basecamp.'
        );
    }


    if (
        $managedUserIsAdmin
        &&
        !$currentAdminIsOwner
    ) {

        http_response_code(
            403
        );


        exit(
            'Administrator accounts are managed by a Llama Scout Owner.'
        );
    }


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


        /* =================================================
           UPDATE ACCOUNT
           ================================================= */

        if (
            $action ===
            'update_account'
        ) {

            $username =
                strtolower(
                    trim(
                        (string) (
                            $_POST[
                                'username'
                            ]
                            ?? ''
                        )
                    )
                );


            $displayName =
                trim(
                    (string) (
                        $_POST[
                            'display_name'
                        ]
                        ?? ''
                    )
                );


            $email =
                strtolower(
                    trim(
                        (string) (
                            $_POST[
                                'email'
                            ]
                            ?? ''
                        )
                    )
                );


            $timezone =
                trim(
                    (string) (
                        $_POST[
                            'timezone'
                        ]
                        ?? llama_default_timezone()
                    )
                );


            $usernamePolicy =
                username_policy_check(
                    $username
                );


            if (
                !$usernamePolicy[
                    'allowed'
                ]
            ) {

                $error =
                    $usernamePolicy[
                        'reason'
                    ];


            } elseif (
                $displayName === ''
                ||
                mb_strlen(
                    $displayName
                ) < 2
                ||
                mb_strlen(
                    $displayName
                ) > 100
            ) {

                $error =
                    'Display name must be between 2 and 100 characters.';


            } elseif (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $error =
                    'Enter a valid email address.';
            }


            if (
                $error === ''
                &&
                !llama_timezone_is_valid(
                    $timezone
                )
            ) {

                $error =
                    'Choose a valid time zone.';
            }


            if (
                $error === ''
            ) {

                $stmt =
                    $db->prepare(
                        '
                        SELECT id

                        FROM users

                        WHERE LOWER(username) = ?
                          AND id != ?

                        LIMIT 1
                        '
                    );


                $stmt->execute([
                    $username,
                    $userId
                ]);


                if (
                    $stmt->fetch()
                ) {

                    $error =
                        'That username is already taken.';
                }
            }


            if (
                $error === ''
            ) {

                $stmt =
                    $db->prepare(
                        '
                        SELECT id

                        FROM users

                        WHERE LOWER(email) = ?
                          AND id != ?

                        LIMIT 1
                        '
                    );


                $stmt->execute([
                    $email,
                    $userId
                ]);


                if (
                    $stmt->fetch()
                ) {

                    $error =
                        'An account already exists with that email address.';
                }
            }


            if (
                $error === ''
            ) {

                $emailChanged =
                    $email
                    !==
                    strtolower(
                        (string)
                        $managedUser[
                            'email'
                        ]
                    );


                try {

                    if (
                        $emailChanged
                    ) {

                        $stmt =
                            $db->prepare(
                                '
                                UPDATE users

                                SET
                                    username = ?,
                                    display_name = ?,
                                    email = ?,
                                    timezone = ?,
                                    email_verified_at = NULL

                                WHERE id = ?
                                '
                            );


                        $stmt->execute([
                            $username,
                            $displayName,
                            $email,
                            $timezone,
                            $userId
                        ]);


                    } else {

                        $stmt =
                            $db->prepare(
                                '
                                UPDATE users

                                SET
                                    username = ?,
                                    display_name = ?,
                                    timezone = ?

                                WHERE id = ?
                                '
                            );


                        $stmt->execute([
                            $username,
                            $displayName,
                            $timezone,
                            $userId
                        ]);
                    }


                    $managedUser =
                        fetch_user(
                            $db,
                            $userId
                        );


                    if (
                        $emailChanged
                    ) {

                        $sent =
                            create_verification(
                                $db,
                                $managedUser
                            );


                        $message =
                            $sent
                                ? 'Account updated. A verification email was sent to the new address.'
                                : 'Account updated, but the verification email could not be sent.';


                    } else {

                        $message =
                            'Account information updated.';
                    }


                } catch (
                    Throwable $exception
                ) {

                    error_log(
                        'Llama Scout admin account edit error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The account information could not be updated.';
                }
            }


        /* =================================================
           PASSWORD RESET
           ================================================= */

        } elseif (
            $action ===
            'send_password_reset'
        ) {

            try {

                $sent =
                    create_password_reset(
                        $db,
                        $managedUser
                    );


                $message =
                    $sent
                        ? 'Password reset email sent to '
                          .
                          $managedUser[
                              'email'
                          ]
                          .
                          '.'
                        : 'The reset link was created, but the email could not be sent.';


            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout admin password reset error: '
                    .
                    $exception
                        ->getMessage()
                );


                $error =
                    'The password reset email could not be created.';
            }


        /* =================================================
           VERIFICATION EMAIL
           ================================================= */

        } elseif (
            $action ===
            'send_verification'
        ) {

            try {

                $sent =
                    create_verification(
                        $db,
                        $managedUser
                    );


                $message =
                    $sent
                        ? 'Verification email sent to '
                          .
                          $managedUser[
                              'email'
                          ]
                          .
                          '.'
                        : 'A verification link was created, but the email could not be sent.';


            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout admin verification email error: '
                    .
                    $exception
                        ->getMessage()
                );


                $error =
                    'The verification email could not be created.';
            }


        /* =================================================
           RESET MEMBERSHIP TEST
           OWNER ONLY
           ================================================= */

        } elseif (
            $action ===
            'reset_membership_test'
        ) {

            if (
                !$currentAdminIsOwner
                ||
                $managedUserIsAdmin
            ) {

                $error =
                    'Only an Owner can use Account Cleanup on this account.';


            } else {

                try {

                    $stripeResult =
                        admin_cleanup_stripe_test_customer(
                            $managedUser[
                                'stripe_subscription_id'
                            ]
                            ?? null,
                            $managedUser[
                                'stripe_customer_id'
                            ]
                            ?? null
                        );


                    $db->beginTransaction();


                    admin_cleanup_reset_membership_columns(
                        $db,
                        $userId
                    );


                    admin_cleanup_delete_by_user_id(
                        $db,
                        'membership_grants',
                        $userId
                    );


                    if (
                        isset(
                            $_POST[
                                'clear_saved_places'
                            ]
                        )
                    ) {

                        admin_cleanup_delete_by_user_id(
                            $db,
                            'user_saved_places',
                            $userId
                        );


                        admin_cleanup_delete_by_user_id(
                            $db,
                            'saved_places',
                            $userId
                        );
                    }


                    if (
                        isset(
                            $_POST[
                                'clear_unpublished_submissions'
                            ]
                        )
                    ) {

                        admin_cleanup_delete_unpublished_content(
                            $db,
                            $userId
                        );
                    }


                    llama_membership_audit(
                        $db,
                        $currentAdminId,
                        'membership_test_reset',
                        'user',
                        $userId,
                        [
                            'stripe_test_mode' =>
                                admin_cleanup_stripe_is_test_mode(),

                            'stripe_subscription_canceled' =>
                                $stripeResult[
                                    'subscription_canceled'
                                ],

                            'stripe_customer_deleted' =>
                                $stripeResult[
                                    'customer_deleted'
                                ],
                        ]
                    );


                    $db->commit();


                    $message =
                        'Membership test state was reset successfully.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    error_log(
                        'Llama Scout membership reset error for user #'
                        .
                        $userId
                        .
                        ': '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        $exception
                            ->getMessage();
                }
            }


        /* =================================================
           ANONYMIZE ACCOUNT
           OWNER ONLY
           ================================================= */

        } elseif (
            $action ===
            'anonymize_account'
        ) {

            if (
                !$currentAdminIsOwner
                ||
                $managedUserIsAdmin
            ) {

                $error =
                    'Only an Owner can anonymize this account.';


            } elseif (
                !isset(
                    $_POST[
                        'understand_anonymize'
                    ]
                )
            ) {

                $error =
                    'Confirm that you understand anonymization permanently removes the account identity and privileges.';


            } else {

                try {

                    admin_cleanup_stripe_test_customer(
                        $managedUser[
                            'stripe_subscription_id'
                        ]
                        ?? null,
                        $managedUser[
                            'stripe_customer_id'
                        ]
                        ?? null
                    );


                    $db->beginTransaction();


                    foreach (
                        [
                            'user_remember_tokens',
                            'email_verifications',
                            'password_resets',
                            'membership_grants',
                            'user_saved_places',
                            'saved_places',
                            'user_badges',
                            'scout_profiles',
                            'scout_applications',
                        ]
                        as
                        $table
                    ) {

                        admin_cleanup_delete_by_user_id(
                            $db,
                            $table,
                            $userId
                        );
                    }


                    admin_cleanup_reset_community_profile(
                        $db,
                        $userId
                    );


                    admin_cleanup_delete_unpublished_content(
                        $db,
                        $userId
                    );


                    admin_cleanup_assign_member_role(
                        $db,
                        $userId
                    );


                    admin_cleanup_reset_membership_columns(
                        $db,
                        $userId
                    );


                    $anonymousUsername =
                        admin_cleanup_anonymize_user(
                            $db,
                            $userId
                        );


                    llama_membership_audit(
                        $db,
                        $currentAdminId,
                        'account_anonymized',
                        'user',
                        $userId,
                        [
                            'replacement_username' =>
                                $anonymousUsername,

                            'initiated_by' =>
                                'owner_account_editor',
                        ]
                    );


                    $db->commit();


                    $message =
                        'The account was anonymized. Historical contribution provenance was preserved.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    error_log(
                        'Llama Scout account anonymization error for user #'
                        .
                        $userId
                        .
                        ': '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        $exception
                            ->getMessage();
                }
            }


        /* =================================================
           PERMANENT DELETE
           OWNER ONLY
           ================================================= */

        } elseif (
            $action ===
            'permanently_delete_account'
        ) {

            if (
                !$currentAdminIsOwner
                ||
                $managedUserIsAdmin
            ) {

                $error =
                    'Only an Owner can permanently delete this account.';


            } elseif (
                !isset(
                    $_POST[
                        'understand_delete'
                    ]
                )
            ) {

                $error =
                    'Confirm that you understand permanent deletion cannot be undone.';


            } else {

                try {

                    $publishedPlaces =
                        admin_cleanup_published_place_count(
                            $db,
                            $userId
                        );


                    $approvedUpdates =
                        admin_cleanup_approved_update_count(
                            $db,
                            $userId
                        );


                    $hasScoutProfile =
                        admin_cleanup_has_scout_profile(
                            $db,
                            $userId
                        );


                    if (
                        $publishedPlaces > 0
                        ||
                        $approvedUpdates > 0
                        ||
                        $hasScoutProfile
                        ||
                        in_array(
                            'scout',
                            $managedRoleSlugs,
                            true
                        )
                        ||
                        in_array(
                            'master-scout',
                            $managedRoleSlugs,
                            true
                        )
                        ||
                        in_array(
                            'master_scout',
                            $managedRoleSlugs,
                            true
                        )
                    ) {

                        throw new RuntimeException(
                            'This account has historical contribution or Scout provenance. Anonymize it instead of permanently deleting it.'
                        );
                    }


                    admin_cleanup_stripe_test_customer(
                        $managedUser[
                            'stripe_subscription_id'
                        ]
                        ?? null,
                        $managedUser[
                            'stripe_customer_id'
                        ]
                        ?? null
                    );


                    $db->beginTransaction();


                    admin_cleanup_remove_profile_files(
                        $db,
                        $userId
                    );


                    foreach (
                        [
                            'user_remember_tokens',
                            'user_saved_places',
                            'saved_places',
                            'membership_grants',
                            'community_profile_images',
                            'community_profiles',
                            'user_badges',
                            'scout_profiles',
                            'scout_applications',
                            'place_update_submissions',
                            'email_verifications',
                            'password_resets',
                            'place_submissions',
                            'user_roles',
                        ]
                        as
                        $table
                    ) {

                        admin_cleanup_delete_by_user_id(
                            $db,
                            $table,
                            $userId
                        );
                    }


                    $stmt =
                        $db->prepare(
                            '
                            DELETE FROM users

                            WHERE id = ?
                            '
                        );


                    $stmt->execute([
                        $userId
                    ]);


                    if (
                        $stmt->rowCount()
                        !== 1
                    ) {

                        throw new RuntimeException(
                            'The user record could not be deleted.'
                        );
                    }


                    $db->commit();


                    header(
                        'Location: /users.php?notice='
                        .
                        rawurlencode(
                            'Account permanently deleted.'
                        )
                    );


                    exit;


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    error_log(
                        'Llama Scout permanent deletion error for user #'
                        .
                        $userId
                        .
                        ': '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        $exception
                            ->getMessage();
                }
            }


        } else {

            $error =
                'That admin action is not supported.';
        }
    }
}


/* =========================================================
   REFRESH USER AFTER ACTIONS
   ========================================================= */


$managedUser =
    fetch_user(
        $db,
        $userId
    );


if (!$managedUser) {

    header(
        'Location: /users.php'
    );


    exit;
}


$managedRoleSlugs =
    fetch_role_slugs(
        $db,
        $userId
    );


$managedUserIsAdmin =
    in_array(
        'admin',
        $managedRoleSlugs,
        true
    );


$hasScoutProfile =
    admin_cleanup_has_scout_profile(
        $db,
        $userId
    );


$hasScoutIdentity =
    $hasScoutProfile
    ||
    in_array(
        'scout',
        $managedRoleSlugs,
        true
    )
    ||
    in_array(
        'master-scout',
        $managedRoleSlugs,
        true
    )
    ||
    in_array(
        'master_scout',
        $managedRoleSlugs,
        true
    );


$publishedPlaceCount =
    admin_cleanup_published_place_count(
        $db,
        $userId
    );


$approvedUpdateCount =
    admin_cleanup_approved_update_count(
        $db,
        $userId
    );


$stripeTestMode =
    admin_cleanup_stripe_is_test_mode();


$isAnonymized =
    str_starts_with(
        (string)
        $managedUser[
            'username'
        ],
        'user_'
    )
    &&
    (
        $managedUser[
            'status'
        ]
        ?? ''
    ) === 'disabled';


$displayHeading =
    trim(
        (string) (
            $managedUser[
                'display_name'
            ]
            ?: $managedUser[
                'username'
            ]
            ?: $managedUser[
                'email'
            ]
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
    Edit <?= e(
        $displayHeading
    ) ?> | Llama Scout Basecamp
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
          Edit Account
        </h1>


        <p>

          <?= e(
              $displayHeading
          ) ?>

          &middot;

          User
          #<?= $userId ?>


          <?php if (
              $managedUserIsAdmin
          ): ?>

            &middot;

            Administrator Account

          <?php endif; ?>

        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/user.php?id=<?= $userId ?>"
        >

          <i
            class="fa-solid fa-arrow-left"
            aria-hidden="true"
          ></i>

          User Overview

        </a>

      </div>

    </div>

  </section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <?php if (
      $managedUserIsAdmin
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--info
      "
    >

      <p>

        <strong>
          Administrator account
        </strong>

        Only an Owner can edit this account.

      </p>

    </div>

  <?php endif; ?>


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
       ACCOUNT INFORMATION
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Account Information
        </h2>

        <p>
          Update the member's public account identity,
          email address, and time zone.
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
        value="update_account"
      >

      <input
        type="hidden"
        name="user_id"
        value="<?= $userId ?>"
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

          <label for="display_name">
            Display Name
          </label>

          <input
            id="display_name"
            name="display_name"
            type="text"
            maxlength="100"
            value="<?= e(
                $managedUser[
                    'display_name'
                ]
            ) ?>"
            required
          >

        </div>


        <div class="admin-field">

          <label for="username">
            Username
          </label>

          <input
            id="username"
            name="username"
            type="text"
            minlength="4"
            maxlength="16"
            value="<?= e(
                $managedUser[
                    'username'
                ]
            ) ?>"
            required
          >

          <p class="admin-field-help">
            4 to 16 characters.
            Letters, numbers, and underscores only.
          </p>

        </div>


        <div class="admin-field">

          <label for="email">
            Email Address
          </label>

          <input
            id="email"
            name="email"
            type="email"
            maxlength="255"
            value="<?= e(
                $managedUser[
                    'email'
                ]
            ) ?>"
            required
          >

          <p class="admin-field-help">
            Changing the email address clears
            its verified status and sends a new
            verification email.
          </p>

        </div>


        <div class="admin-field">

          <label for="timezone">
            Time Zone
          </label>

          <select
            id="timezone"
            name="timezone"
            required
          >

            <?php foreach (
                llama_timezones()
                as
                $zone =>
                $label
            ): ?>

              <option
                value="<?= e(
                    $zone
                ) ?>"
                <?= llama_user_timezone(
                    $managedUser
                ) === $zone
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

          <p class="admin-field-help">
            Controls how dates and times
            are shown for this user.
          </p>

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

          Save Account Information

        </button>

      </div>

    </form>

  </section>


  <!-- =====================================================
       EMAIL VERIFICATION
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Email Verification
        </h2>

        <p>
          Review verification status or send
          a fresh verification link.
        </p>

      </div>


      <?php if (
          !empty(
              $managedUser[
                  'email_verified_at'
              ]
          )
      ): ?>

        <span
          class="
            admin-badge
            admin-badge--success
          "
        >
          Verified
        </span>

      <?php else: ?>

        <span
          class="
            admin-badge
            admin-badge--warning
          "
        >
          Verification Required
        </span>

      <?php endif; ?>

    </div>


    <div class="admin-detail-list">

      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Email
        </div>

        <div class="admin-detail-value">

          <?= e(
              $managedUser[
                  'email'
              ]
          ) ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Verification
        </div>

        <div class="admin-detail-value">

          <?= !empty(
              $managedUser[
                  'email_verified_at'
              ]
          )
              ? 'Verified'
              : 'Not yet verified'
          ?>

        </div>

      </div>

    </div>


    <form
      method="post"
      class="
        admin-form
        admin-form--spaced
      "
    >

      <input
        type="hidden"
        name="action"
        value="send_verification"
      >

      <input
        type="hidden"
        name="user_id"
        value="<?= $userId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <div class="admin-form-actions">

        <button
          type="submit"
          class="
            admin-button
            admin-button--secondary
          "
        >

          <i
            class="fa-solid fa-envelope"
            aria-hidden="true"
          ></i>

          Send Verification Email

        </button>

      </div>

    </form>

  </section>


  <!-- =====================================================
       PASSWORD ASSISTANCE
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Password Assistance
        </h2>

        <p>
          Send the user a secure one-time
          password reset link.
        </p>

      </div>

    </div>


    <div
      class="
        admin-notice
        admin-notice--info
      "
    >

      <p>
        Llama Scout never displays or sends
        a user's password. The reset link is sent
        to the account email, expires after
        60 minutes, and can only be used once.
      </p>

    </div>


    <form
      method="post"
      class="admin-form"
    >

      <input
        type="hidden"
        name="action"
        value="send_password_reset"
      >

      <input
        type="hidden"
        name="user_id"
        value="<?= $userId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <div class="admin-form-actions">

        <button
          type="submit"
          class="admin-button"
        >

          <i
            class="fa-solid fa-key"
            aria-hidden="true"
          ></i>

          Send Password Reset Link

        </button>

      </div>

    </form>

  </section>


  <!-- =====================================================
       ACCOUNT CLEANUP
       ===================================================== -->

  <section
    class="
      admin-panel
      admin-account-cleanup
    "
  >

    <div class="admin-panel-header">

      <div>

        <h2>
          Account Cleanup
        </h2>

        <p>
          Owner-only account reset, anonymization,
          and disposable-account removal tools.
        </p>

      </div>

      <?php if (
          $currentAdminIsOwner
      ): ?>

        <span
          class="
            admin-badge
            admin-badge--warning
          "
        >
          Owner Only
        </span>

      <?php endif; ?>

    </div>


    <?php if (
        !$currentAdminIsOwner
    ): ?>

      <div
        class="
          admin-notice
          admin-notice--info
        "
      >

        <p>
          Account Cleanup is restricted to a Llama Scout Owner.
        </p>

      </div>

    <?php elseif (
        $managedUserIsAdmin
    ): ?>

      <div
        class="
          admin-notice
          admin-notice--warning
        "
      >

        <p>
          Administrator accounts are protected from Account
          Cleanup. Remove Admin access through the normal
          role-management workflow first.
        </p>

      </div>

    <?php else: ?>


      <div class="admin-cleanup-summary">

        <div>

          <span>
            Published Places
          </span>

          <strong>
            <?= $publishedPlaceCount ?>
          </strong>

        </div>


        <div>

          <span>
            Approved Updates
          </span>

          <strong>
            <?= $approvedUpdateCount ?>
          </strong>

        </div>


        <div>

          <span>
            Scout History
          </span>

          <strong>
            <?= $hasScoutIdentity
                ? 'Yes'
                : 'No'
            ?>
          </strong>

        </div>


        <div>

          <span>
            Stripe Mode
          </span>

          <strong>
            <?= $stripeTestMode
                ? 'Test'
                : 'Live / unavailable'
            ?>
          </strong>

        </div>

      </div>


      <?php if (
          $isAnonymized
      ): ?>

        <div
          class="
            admin-notice
            admin-notice--info
            admin-cleanup-notice
          "
        >

          <p>
            This account has already been anonymized as
            <strong>
              <?= e(
                  $managedUser[
                      'username'
                  ]
              ) ?>
            </strong>.
          </p>

        </div>

      <?php endif; ?>


      <!-- ===============================================
           RESET TEST STATE
           =============================================== -->

      <article class="admin-cleanup-card">

        <div>

          <h3>
            Reset Membership Test
          </h3>

          <p>
            Keep the account but remove reusable membership
            test state so it can go through membership testing
            again.
          </p>

        </div>


        <form
          method="post"
          class="admin-form"
        >

          <input
            type="hidden"
            name="action"
            value="reset_membership_test"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= $userId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >


          <div class="admin-cleanup-checks">

            <label class="admin-cleanup-check">

              <input
                type="checkbox"
                name="clear_saved_places"
                value="1"
                checked
              >

              <span>
                Clear Saved Places
              </span>

            </label>


            <label class="admin-cleanup-check">

              <input
                type="checkbox"
                name="clear_unpublished_submissions"
                value="1"
              >

              <span>
                Clear draft and non-approved submissions
              </span>

            </label>

          </div>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="admin-button"
              <?= $isAnonymized
                  ? 'disabled'
                  : ''
              ?>
            >

              <i
                class="fa-solid fa-rotate-left"
                aria-hidden="true"
              ></i>

              Reset Membership Test

            </button>

          </div>

        </form>

      </article>


      <!-- ===============================================
           ANONYMIZE
           =============================================== -->

      <article
        class="
          admin-cleanup-card
          admin-cleanup-card--warning
        "
      >

        <div>

          <h3>
            Anonymize Deleted Account
          </h3>

          <p>
            Remove the user's personal identity, profile,
            badges, membership access, and Scout privileges
            while preserving published contribution history.
          </p>

          <p class="admin-field-help">
            The retained account becomes
            <strong>
              <?= e(
                  admin_cleanup_deleted_username(
                      $userId
                  )
              ) ?>
            </strong>
            / Deleted User and is disabled from logging in.
          </p>

        </div>


        <form
          method="post"
          class="admin-form"
        >

          <input
            type="hidden"
            name="action"
            value="anonymize_account"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= $userId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >


          <label class="admin-cleanup-check">

            <input
              type="checkbox"
              name="understand_anonymize"
              value="1"
              required
            >

            <span>
              I understand this permanently removes the
              account identity, badges, membership access,
              profile data, and Scout privileges.
            </span>

          </label>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="
                admin-button
                admin-button--cleanup-warning
              "
              <?= $isAnonymized
                  ? 'disabled'
                  : ''
              ?>
              onclick="
                return confirm(
                  'Anonymize this account now? This permanently removes the user identity and privileges while preserving historical contribution records.'
                );
              "
            >

              <i
                class="fa-solid fa-user-shield"
                aria-hidden="true"
              ></i>

              Anonymize Account

            </button>

          </div>

        </form>

      </article>


      <!-- ===============================================
           PERMANENT DELETE
           =============================================== -->

      <article
        class="
          admin-cleanup-card
          admin-cleanup-card--danger
        "
      >

        <div>

          <h3>
            Permanently Delete Disposable Account
          </h3>

          <p>
            Permanently remove the user row and disposable
            account-owned data. This is only available when
            there is no historical Place, approved update,
            or Scout provenance to preserve.
          </p>

        </div>


        <?php if (
            $publishedPlaceCount > 0
            ||
            $approvedUpdateCount > 0
            ||
            $hasScoutIdentity
        ): ?>

          <div
            class="
              admin-notice
              admin-notice--warning
              admin-cleanup-notice
            "
          >

            <p>
              Permanent deletion is blocked for this account.
              Use <strong>Anonymize Account</strong> instead.
            </p>

          </div>

        <?php endif; ?>


        <form
          method="post"
          class="admin-form"
        >

          <input
            type="hidden"
            name="action"
            value="permanently_delete_account"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= $userId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >


          <label class="admin-cleanup-check">

            <input
              type="checkbox"
              name="understand_delete"
              value="1"
              required
            >

            <span>
              I understand permanent deletion cannot be undone.
            </span>

          </label>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="
                admin-button
                admin-button--cleanup-danger
              "
              <?= (
                  $publishedPlaceCount > 0
                  ||
                  $approvedUpdateCount > 0
                  ||
                  $hasScoutIdentity
              )
                  ? 'disabled'
                  : ''
              ?>
              onclick="
                return confirm(
                  'Permanently delete this disposable account? This cannot be undone.'
                );
              "
            >

              <i
                class="fa-solid fa-trash-can"
                aria-hidden="true"
              ></i>

              Permanently Delete Account

            </button>

          </div>

        </form>

      </article>


    <?php endif; ?>

  </section>


  <div class="admin-foot-actions">

    <a href="/user.php?id=<?= $userId ?>">
      User Overview
    </a>

    <a href="/users.php">
      All Users
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
