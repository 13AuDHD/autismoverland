<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   ACCOUNT CLEANUP
   admin/user-cleanup.php

   OWNER-ONLY TOOL

   Three distinct actions live here:

   1. Reset Membership Test
      Keeps the account but clears reusable test state.

   2. Anonymize Deleted Account
      Removes personal account identity while preserving the
      numeric user ID and historical contribution provenance.

   3. Permanently Delete Disposable Account
      Deletes the user row only when doing so will not erase
      protected historical contribution records.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';

require_once
    dirname(__DIR__)
    . '/app/memberships.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'owner'
);


start_llama_session();


$db =
    db();


$owner =
    current_user();


if (!$owner) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );
}


$ownerId =
    (int)
    $owner['id'];


$primaryRoleLabel =
    llama_primary_role_label(
        $ownerId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $ownerId
    );


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


function cleanup_table_exists(
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


function cleanup_column_exists(
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


function cleanup_delete_by_user_id(
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
        !cleanup_table_exists(
            $db,
            $table
        )
        ||
        !cleanup_column_exists(
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


function cleanup_user_roles(
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

            ORDER BY r.slug ASC
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        array_values(
            array_filter(
                array_map(
                    'strval',
                    array_column(
                        $stmt->fetchAll(
                            PDO::FETCH_ASSOC
                        ),
                        'slug'
                    )
                )
            )
        );
}


function cleanup_user_has_role(
    array $roles,
    array $slugs
): bool {

    foreach (
        $slugs
        as
        $slug
    ) {

        if (
            in_array(
                $slug,
                $roles,
                true
            )
        ) {

            return true;
        }
    }


    return false;
}


function cleanup_user_has_scout_profile(
    PDO $db,
    int $userId
): bool {

    if (
        !cleanup_table_exists(
            $db,
            'scout_profiles'
        )
        ||
        !cleanup_column_exists(
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


function cleanup_published_place_count(
    PDO $db,
    int $userId
): int {

    if (
        !cleanup_table_exists(
            $db,
            'place_submissions'
        )
        ||
        !cleanup_column_exists(
            $db,
            'place_submissions',
            'user_id'
        )
        ||
        !cleanup_column_exists(
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


function cleanup_approved_update_count(
    PDO $db,
    int $userId
): int {

    if (
        !cleanup_table_exists(
            $db,
            'place_update_submissions'
        )
        ||
        !cleanup_column_exists(
            $db,
            'place_update_submissions',
            'user_id'
        )
        ||
        !cleanup_column_exists(
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


function cleanup_deleted_username(
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


function cleanup_deleted_email(
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


function cleanup_random_password_hash(): string {

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


function cleanup_assign_member_role(
    PDO $db,
    int $userId
): void {

    cleanup_delete_by_user_id(
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


function cleanup_stripe_is_test_mode(): bool {

    try {

        $config =
            llama_stripe_config();


        $secret =
            trim(
                (string) (
                    $config['secret_key']
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


function cleanup_stripe_test_customer(
    ?string $subscriptionId,
    ?string $customerId
): array {

    $subscriptionId =
        trim(
            (string) $subscriptionId
        );

    $customerId =
        trim(
            (string) $customerId
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
        !cleanup_stripe_is_test_mode()
    ) {

        throw new RuntimeException(
            'This account contains Stripe billing data. Llama Scout is not currently using a Stripe test secret key, so automatic Stripe deletion is blocked. Resolve live billing first, then return to Account Cleanup.'
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

            $message =
                strtolower(
                    $exception
                        ->getMessage()
                );


            if (
                !str_contains(
                    $message,
                    'no such subscription'
                )
                &&
                !str_contains(
                    $message,
                    'resource_missing'
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

            $message =
                strtolower(
                    $exception
                        ->getMessage()
                );


            if (
                !str_contains(
                    $message,
                    'no such customer'
                )
                &&
                !str_contains(
                    $message,
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


function cleanup_reset_membership_columns(
    PDO $db,
    int $userId
): void {

    $values = [

        'stripe_customer_id' =>
            null,

        'stripe_subscription_id' =>
            null,

        'stripe_cancel_at_period_end' =>
            0,

        'membership_status' =>
            'none',

        'membership_interval' =>
            null,

        'membership_started_at' =>
            null,

        'membership_ends_at' =>
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
            cleanup_column_exists(
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


function cleanup_delete_unpublished_content(
    PDO $db,
    int $userId
): void {

    if (
        cleanup_table_exists(
            $db,
            'place_submissions'
        )
        &&
        cleanup_column_exists(
            $db,
            'place_submissions',
            'user_id'
        )
        &&
        cleanup_column_exists(
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
        cleanup_table_exists(
            $db,
            'place_update_submissions'
        )
        &&
        cleanup_column_exists(
            $db,
            'place_update_submissions',
            'user_id'
        )
        &&
        cleanup_column_exists(
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


function cleanup_remove_profile_files(
    PDO $db,
    int $userId
): void {

    if (
        !cleanup_table_exists(
            $db,
            'community_profile_images'
        )
        ||
        !cleanup_column_exists(
            $db,
            'community_profile_images',
            'user_id'
        )
        ||
        !cleanup_column_exists(
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


    $docRoot =
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
            ||
            !is_string(
                $docRoot
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
                $docRoot
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


function cleanup_reset_community_profile(
    PDO $db,
    int $userId
): void {

    cleanup_remove_profile_files(
        $db,
        $userId
    );


    cleanup_delete_by_user_id(
        $db,
        'community_profile_images',
        $userId
    );


    if (
        !cleanup_table_exists(
            $db,
            'community_profiles'
        )
        ||
        !cleanup_column_exists(
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
            cleanup_column_exists(
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


function cleanup_anonymize_user(
    PDO $db,
    int $userId
): string {

    $username =
        cleanup_deleted_username(
            $userId
        );


    $email =
        cleanup_deleted_email(
            $userId
        );


    $passwordHash =
        cleanup_random_password_hash();


    $values = [
        'email' => $email,
        'username' => $username,
        'display_name' => 'Deleted User',
        'password_hash' => $passwordHash,
        'timezone' => 'America/Denver',
        'status' => 'disabled',
        'email_verified_at' => null,
        'last_login_at' => null,
        'dormancy_notice_sent_at' => null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            cleanup_column_exists(
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

        throw new RuntimeException(
            'No account identity columns were available to anonymize.'
        );
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


    if (
        $stmt->rowCount() < 1
    ) {

        /*
         * rowCount can be zero only if every stored value was
         * already identical. Confirm the row still exists.
         */

        $check =
            $db->prepare(
                '
                SELECT id

                FROM users

                WHERE id = ?

                LIMIT 1
                '
            );


        $check->execute([
            $userId
        ]);


        if (
            !$check->fetchColumn()
        ) {

            throw new RuntimeException(
                'The user record could not be anonymized.'
            );
        }
    }


    return
        $username;
}


function cleanup_reload_user(
    PDO $db,
    int $userId
): ?array {

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


    $user =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        is_array(
            $user
        )
            ? $user
            : null;
}


/* =========================================================
   TARGET ACCOUNT
   ========================================================= */


$userId =
    (int) (
        $_GET['id']
        ??
        $_POST['user_id']
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


$managedUser =
    cleanup_reload_user(
        $db,
        $userId
    );


if (!$managedUser) {

    http_response_code(
        404
    );

    exit(
        'User not found.'
    );
}


if (
    $userId ===
    $ownerId
) {

    http_response_code(
        403
    );

    exit(
        'Your Owner account cannot be deleted, anonymized, or reset from Account Cleanup.'
    );
}


$managedRoles =
    cleanup_user_roles(
        $db,
        $userId
    );


$hasScoutProfile =
    cleanup_user_has_scout_profile(
        $db,
        $userId
    );


$ownerOrAdmin =
    cleanup_user_has_role(
        $managedRoles,
        [
            'owner',
            'admin',
        ]
    );


$scoutIdentity =
    cleanup_user_has_role(
        $managedRoles,
        [
            'scout',
            'master-scout',
            'master_scout',
        ]
    )
    ||
    $hasScoutProfile;


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'owner_user_cleanup_csrf'
        ]
    )
) {

    $_SESSION[
        'owner_user_cleanup_csrf'
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
        'owner_user_cleanup_csrf'
    ];


/* =========================================================
   STATE
   ========================================================= */


$message =
    '';

$error =
    '';

$deleted =
    false;

$anonymized =
    (
        str_starts_with(
            (string) (
                $managedUser['username']
                ?? ''
            ),
            'user_'
        )
        &&
        (
            $managedUser['status']
            ?? ''
        ) === 'disabled'
    );


/* =========================================================
   POST ACTIONS
   ========================================================= */


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        $_POST['csrf_token']
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
                    $_POST['action']
                    ?? ''
                )
            );


        /*
         * Reload roles immediately before any destructive
         * operation so a stale page cannot bypass protection.
         */

        $managedRoles =
            cleanup_user_roles(
                $db,
                $userId
            );


        $hasScoutProfile =
            cleanup_user_has_scout_profile(
                $db,
                $userId
            );


        $ownerOrAdmin =
            cleanup_user_has_role(
                $managedRoles,
                [
                    'owner',
                    'admin',
                ]
            );


        $scoutIdentity =
            cleanup_user_has_role(
                $managedRoles,
                [
                    'scout',
                    'master-scout',
                    'master_scout',
                ]
            )
            ||
            $hasScoutProfile;


        if (
            $ownerOrAdmin
        ) {

            $error =
                'Owner and Admin accounts are protected from Account Cleanup. Remove privileged access through the proper administration workflow first.';


        } elseif (
            $action ===
            'reset_membership_test'
        ) {

            try {

                $stripeResult =
                    cleanup_stripe_test_customer(
                        $managedUser[
                            'stripe_subscription_id'
                        ]
                        ?? null,
                        $managedUser[
                            'stripe_customer_id'
                        ]
                        ?? null
                    );


                $clearSaved =
                    isset(
                        $_POST[
                            'clear_saved_places'
                        ]
                    );


                $clearUnpublishedSubmissions =
                    isset(
                        $_POST[
                            'clear_unpublished_submissions'
                        ]
                    );


                $clearPlaceUpdates =
                    isset(
                        $_POST[
                            'clear_place_updates'
                        ]
                    );


                $db->beginTransaction();


                cleanup_reset_membership_columns(
                    $db,
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'membership_grants',
                    $userId
                );


                if (
                    $clearSaved
                ) {

                    cleanup_delete_by_user_id(
                        $db,
                        'user_saved_places',
                        $userId
                    );


                    cleanup_delete_by_user_id(
                        $db,
                        'saved_places',
                        $userId
                    );
                }


                if (
                    $clearUnpublishedSubmissions
                    &&
                    cleanup_table_exists(
                        $db,
                        'place_submissions'
                    )
                    &&
                    cleanup_column_exists(
                        $db,
                        'place_submissions',
                        'user_id'
                    )
                    &&
                    cleanup_column_exists(
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
                    $clearPlaceUpdates
                    &&
                    cleanup_table_exists(
                        $db,
                        'place_update_submissions'
                    )
                    &&
                    cleanup_column_exists(
                        $db,
                        'place_update_submissions',
                        'user_id'
                    )
                    &&
                    cleanup_column_exists(
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


                llama_membership_audit(
                    $db,
                    $ownerId,
                    'membership_test_reset',
                    'user',
                    $userId,
                    [
                        'stripe_test_mode' =>
                            cleanup_stripe_is_test_mode(),

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


                $managedUser =
                    cleanup_reload_user(
                        $db,
                        $userId
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
                    'Llama Scout membership reset error for user #'
                    .
                    $userId
                    .
                    ': '
                    .
                    $exception->getMessage()
                );


                $error =
                    $exception->getMessage();
            }


        } elseif (
            $action ===
            'anonymize_account'
        ) {

            try {

                if (
                    !isset(
                        $_POST[
                            'understand_anonymize'
                        ]
                    )
                ) {

                    throw new RuntimeException(
                        'Confirm that you understand anonymization permanently removes the account identity and privileges.'
                    );
                }


                if (
                    $anonymized
                ) {

                    throw new RuntimeException(
                        'This account has already been anonymized.'
                    );
                }


                /*
                 * Test Stripe records can be removed
                 * automatically. Live Stripe-linked accounts
                 * must have live billing resolved first.
                 */

                cleanup_stripe_test_customer(
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


                /*
                 * Remove personal / reusable account state.
                 */

                cleanup_delete_by_user_id(
                    $db,
                    'user_remember_tokens',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'email_verifications',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'password_resets',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'membership_grants',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'user_saved_places',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'saved_places',
                    $userId
                );


                /*
                 * Badges and Scout privileges are forfeited.
                 * Historical Scout/place contributions are
                 * intentionally NOT deleted.
                 */

                cleanup_delete_by_user_id(
                    $db,
                    'user_badges',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'scout_profiles',
                    $userId
                );


                cleanup_delete_by_user_id(
                    $db,
                    'scout_applications',
                    $userId
                );


                cleanup_reset_community_profile(
                    $db,
                    $userId
                );


                /*
                 * Draft/unapproved contribution material does
                 * not need to survive account deletion.
                 * Published and approved history remains tied
                 * to the stable numeric user ID.
                 */

                cleanup_delete_unpublished_content(
                    $db,
                    $userId
                );


                /*
                 * Remove all privileged roles and leave only
                 * the neutral Member role for referential and
                 * display consistency. The account itself is
                 * disabled and cannot authenticate.
                 */

                cleanup_assign_member_role(
                    $db,
                    $userId
                );


                cleanup_reset_membership_columns(
                    $db,
                    $userId
                );


                $anonymousUsername =
                    cleanup_anonymize_user(
                        $db,
                        $userId
                    );


                /*
                 * Do not record the old username, display
                 * name, or email in the audit payload.
                 */

                llama_membership_audit(
                    $db,
                    $ownerId,
                    'account_anonymized',
                    'user',
                    $userId,
                    [
                        'replacement_username' =>
                            $anonymousUsername,

                        'published_places_preserved' =>
                            cleanup_published_place_count(
                                $db,
                                $userId
                            ),

                        'approved_updates_preserved' =>
                            cleanup_approved_update_count(
                                $db,
                                $userId
                            ),
                    ]
                );


                $db->commit();


                $anonymized =
                    true;


                $message =
                    'The account was anonymized. Personal identity, badges, profile data, saved data, membership access, and Scout privileges were removed while historical contribution provenance was preserved.';


                $managedUser =
                    cleanup_reload_user(
                        $db,
                        $userId
                    );


                $managedRoles =
                    cleanup_user_roles(
                        $db,
                        $userId
                    );


                $scoutIdentity =
                    false;


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
                    $exception->getMessage()
                );


                $error =
                    $exception->getMessage();
            }


        } elseif (
            $action ===
            'permanently_delete_account'
        ) {

            try {

                $publishedPlaces =
                    cleanup_published_place_count(
                        $db,
                        $userId
                    );


                $approvedUpdates =
                    cleanup_approved_update_count(
                        $db,
                        $userId
                    );


                if (
                    $scoutIdentity
                ) {

                    throw new RuntimeException(
                        'This account has Scout or Master Scout identity/history. Anonymize it instead of permanently deleting it.'
                    );
                }


                if (
                    $publishedPlaces > 0
                    ||
                    $approvedUpdates > 0
                ) {

                    throw new RuntimeException(
                        'This account has published contribution history. Anonymize it instead so Llama Scout can preserve historical provenance.'
                    );
                }


                if (
                    !isset(
                        $_POST[
                            'understand_delete'
                        ]
                    )
                ) {

                    throw new RuntimeException(
                        'Confirm that you understand permanent deletion cannot be undone.'
                    );
                }


                cleanup_stripe_test_customer(
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


                cleanup_remove_profile_files(
                    $db,
                    $userId
                );


                $accountTables = [

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
                ];


                foreach (
                    $accountTables
                    as
                    $table
                ) {

                    cleanup_delete_by_user_id(
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


                $deleted =
                    true;


                $message =
                    'The disposable account and its account-owned data were permanently removed from Llama Scout.';


            } catch (
                Throwable $exception
            ) {

                if (
                    $db->inTransaction()
                ) {

                    $db->rollBack();
                }


                error_log(
                    'Llama Scout permanent account deletion error for user #'
                    .
                    $userId
                    .
                    ': '
                    .
                    $exception->getMessage()
                );


                $error =
                    $exception->getMessage();
            }


        } else {

            $error =
                'Unknown Account Cleanup action.';
        }
    }
}


/* =========================================================
   CURRENT SUMMARY
   ========================================================= */


$stripeTestMode =
    cleanup_stripe_is_test_mode();


$publishedPlaceCount =
    !$deleted
        ? cleanup_published_place_count(
            $db,
            $userId
        )
        : 0;


$approvedUpdateCount =
    !$deleted
        ? cleanup_approved_update_count(
            $db,
            $userId
        )
        : 0;


if (
    !$deleted
    &&
    is_array(
        $managedUser
    )
) {

    $anonymized =
        (
            str_starts_with(
                (string) (
                    $managedUser['username']
                    ?? ''
                ),
                'user_'
            )
            &&
            (
                $managedUser['status']
                ?? ''
            ) === 'disabled'
        );
}


?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Account Cleanup | Llama Scout Admin
  </title>


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
    rel="stylesheet"
    href="https://llamascout.com/css/admin-user-cleanup.css"
  >

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="cleanup-main">


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
          Account Cleanup
        </h1>

        <p>
          Reset reusable membership-test data, anonymize an
          account-deletion request while preserving historical
          provenance, or permanently remove a disposable account.
        </p>

      </div>

    </div>

  </section>


  <div class="cleanup-back">

    <a
      href="<?= $deleted
          ? 'users.php'
          : 'user.php?id='
            . (int) $userId
      ?>"
      class="back-link"
    >

      <i
        class="fa-solid fa-arrow-left"
        aria-hidden="true"
      ></i>

      <?= $deleted
          ? 'Back to Users'
          : 'Back to User'
      ?>

    </a>

  </div>


  <?php if (
      $message !== ''
  ): ?>

    <div
      class="
        cleanup-notice
        cleanup-notice--success
      "
    >
      <?= e(
          $message
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div
      class="
        cleanup-notice
        cleanup-notice--error
      "
    >
      <?= e(
          $error
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $deleted
  ): ?>


    <section class="cleanup-card">

      <h2>
        Account Removed
      </h2>

      <p>
        This account no longer exists in Llama Scout.
        Its disposable account-owned database records
        were removed as part of the same transaction.
      </p>

      <div class="cleanup-actions">

        <a
          href="users.php"
          class="cleanup-button"
        >
          View Users
        </a>

      </div>

    </section>


  <?php else: ?>


    <section class="cleanup-card">

      <p class="admin-eyebrow">
        Selected Account
      </p>

      <h2>
        <?= e(
            $managedUser[
                'display_name'
            ]
            ?:
            $managedUser[
                'username'
            ]
            ?:
            $managedUser[
                'email'
            ]
        ) ?>
      </h2>


      <?php if (
          $anonymized
      ): ?>

        <div
          class="
            cleanup-notice
            cleanup-notice--info
          "
        >
          This user identity has already been anonymized.
          Historical records can continue to reference user
          #<?= (int) $userId ?> without exposing the former
          account identity.
        </div>

      <?php endif; ?>


      <div class="cleanup-summary">

        <div>
          <span>User ID</span>

          <strong>
            #<?= (int) $userId ?>
          </strong>
        </div>


        <div>
          <span>Username</span>

          <strong>
            <?= e(
                $managedUser[
                    'username'
                ]
                ?: 'None'
            ) ?>
          </strong>
        </div>


        <div>
          <span>Email</span>

          <strong>
            <?= e(
                $managedUser[
                    'email'
                ]
            ) ?>
          </strong>
        </div>


        <div>
          <span>Time Zone</span>

          <strong>
            <?= e(
                $managedUser[
                    'timezone'
                ]
                ?: 'Not set'
            ) ?>
          </strong>
        </div>


        <div>
          <span>Account Status</span>

          <strong>
            <?= e(
                ucfirst(
                    (string)
                    $managedUser[
                        'status'
                    ]
                )
            ) ?>
          </strong>
        </div>


        <div>
          <span>Email Verified</span>

          <strong>
            <?= !empty(
                $managedUser[
                    'email_verified_at'
                ]
            )
                ? 'Yes'
                : 'No'
            ?>
          </strong>
        </div>


        <div>
          <span>Last Login</span>

          <strong>
            <?= !empty(
                $managedUser[
                    'last_login_at'
                ]
            )
                ? e(
                    $managedUser[
                        'last_login_at'
                    ]
                )
                : 'Never / cleared'
            ?>
          </strong>
        </div>


        <div>
          <span>Membership Status</span>

          <strong>
            <?= e(
                $managedUser[
                    'membership_status'
                ]
                ?: 'none'
            ) ?>
          </strong>
        </div>


        <div>
          <span>Roles</span>

          <strong>
            <?= e(
                $managedRoles
                    ? implode(
                        ', ',
                        $managedRoles
                    )
                    : 'None'
            ) ?>
          </strong>
        </div>


        <div>
          <span>Published Places</span>

          <strong>
            <?= (int)
                $publishedPlaceCount
            ?>
          </strong>
        </div>


        <div>
          <span>Approved Updates</span>

          <strong>
            <?= (int)
                $approvedUpdateCount
            ?>
          </strong>
        </div>


        <div>
          <span>Stripe Customer</span>

          <strong>
            <?= !empty(
                $managedUser[
                    'stripe_customer_id'
                ]
            )
                ? '<code>'
                  . e(
                      $managedUser[
                          'stripe_customer_id'
                      ]
                  )
                  . '</code>'
                : 'None'
            ?>
          </strong>
        </div>


        <div>
          <span>Stripe Subscription</span>

          <strong>
            <?= !empty(
                $managedUser[
                    'stripe_subscription_id'
                ]
            )
                ? '<code>'
                  . e(
                      $managedUser[
                          'stripe_subscription_id'
                      ]
                  )
                  . '</code>'
                : 'None'
            ?>
          </strong>
        </div>

      </div>

    </section>


    <?php if (
        $ownerOrAdmin
    ): ?>

      <div
        class="
          cleanup-notice
          cleanup-notice--warning
          cleanup-notice--spaced
        "
      >
        This account currently has Owner or Admin privileges.
        Account Cleanup is disabled until those privileged
        roles are removed through the proper administration
        workflow.
      </div>

    <?php elseif (
        $scoutIdentity
    ): ?>

      <div
        class="
          cleanup-notice
          cleanup-notice--info
          cleanup-notice--spaced
        "
      >
        This account has Scout or Master Scout identity.
        Anonymization is allowed and will remove those
        privileges while preserving historical Scout
        contribution provenance.
      </div>

    <?php endif; ?>


    <!-- ===================================================
         MEMBERSHIP TEST RESET
         =================================================== -->

    <section class="cleanup-section">

      <article class="cleanup-card">

        <h2>
          Reset Membership Test
        </h2>

        <p>
          Use this only for reusing an account during
          membership and Stripe testing. It keeps the account
          itself and does not honor an account-deletion request.
        </p>


        <div
          class="
            cleanup-notice
            <?= $stripeTestMode
                ? 'cleanup-notice--success'
                : 'cleanup-notice--warning'
            ?>
          "
        >

          <strong>
            Stripe mode:
            <?= $stripeTestMode
                ? 'TEST'
                : 'LIVE or unavailable'
            ?>
          </strong>

          <br>

          <?php if (
              $stripeTestMode
          ): ?>

            Linked Stripe test objects can be removed safely
            while resetting this account.

          <?php else: ?>

            Accounts containing Stripe billing IDs cannot be
            automatically reset through this tool until live
            billing has been resolved.

          <?php endif; ?>

        </div>


        <form method="post">

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= (int)
                $userId
            ?>"
          >

          <input
            type="hidden"
            name="action"
            value="reset_membership_test"
          >


          <div class="cleanup-checks">

            <label class="cleanup-check">

              <input
                type="checkbox"
                name="clear_saved_places"
                value="1"
                checked
              >

              <span>
                Clear Saved Places.
              </span>

            </label>


            <label class="cleanup-check">

              <input
                type="checkbox"
                name="clear_unpublished_submissions"
                value="1"
              >

              <span>
                Delete unpublished new-Place submissions.
              </span>

            </label>


            <label class="cleanup-check">

              <input
                type="checkbox"
                name="clear_place_updates"
                value="1"
              >

              <span>
                Delete non-approved Place Update submissions.
                Approved history is preserved.
              </span>

            </label>

          </div>


          <div class="cleanup-actions">

            <button
              type="submit"
              class="cleanup-button"
              <?= (
                  $ownerOrAdmin
                  ||
                  $anonymized
              )
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

    </section>


    <!-- ===================================================
         ACCOUNT ANONYMIZATION
         =================================================== -->

    <section class="cleanup-section">

      <article
        class="
          cleanup-card
          cleanup-anonymize
        "
      >

        <h2>
          Anonymize Deleted Account
        </h2>

        <p>
          This is the normal account-deletion workflow when
          Llama Scout must preserve historical contributions.
          The stable numeric user ID remains, but the user's
          personal identity and account privileges are removed.
        </p>


        <div
          class="
            cleanup-notice
            cleanup-notice--warning
          "
        >
          The account will become
          <strong><?= e(
              cleanup_deleted_username(
                  $userId
              )
          ) ?></strong>
          with display name
          <strong>Deleted User</strong>,
          Mountain Time, disabled login, no badges, no Scout
          or Master Scout status, no public profile, no saved
          places, and no active membership access.
        </div>


        <ul class="cleanup-detail-list">
          <li>
            Published Places and approved Place Updates remain
            tied to user #<?= (int) $userId ?>.
          </li>
          <li>
            Draft and non-approved Place submissions are removed.
          </li>
          <li>
            Profile photos, profile details, social links,
            badges, saved places, login tokens, and credentials
            are removed or replaced.
          </li>
          <li>
            The original email, username, and display name are
            not copied into the anonymization audit payload.
          </li>
          <li>
            Existing order, payment, accounting, security, or
            other records may remain where Llama Scout has a
            legitimate retention reason.
          </li>
        </ul>


        <?php if (
            !$stripeTestMode
            &&
            (
                !empty(
                    $managedUser[
                        'stripe_customer_id'
                    ]
                )
                ||
                !empty(
                    $managedUser[
                        'stripe_subscription_id'
                    ]
                )
            )
        ): ?>

          <div
            class="
              cleanup-notice
              cleanup-notice--warning
              cleanup-notice--spaced
            "
          >
            This account still has live Stripe identifiers.
            Resolve/cancel live billing first. The anonymization
            action will intentionally refuse to continue while
            those identifiers remain attached.
          </div>

        <?php endif; ?>


        <form method="post">

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= (int)
                $userId
            ?>"
          >

          <input
            type="hidden"
            name="action"
            value="anonymize_account"
          >


          <label
            class="
              cleanup-check
              cleanup-confirm
            "
          >

            <input
              type="checkbox"
              name="understand_anonymize"
              value="1"
              required
            >

            <span>
              I understand this permanently removes the
              user's personal account identity, badges,
              membership access, and Scout privileges while
              preserving required historical records.
            </span>

          </label>


          <div class="cleanup-actions">

            <button
              type="submit"
              class="
                cleanup-button
                cleanup-button--anonymize
              "
              onclick="
                return confirm(
                  'Anonymize this account? Personal identity and privileges will be permanently removed while historical contribution records are preserved.'
                );
              "
              <?= (
                  $ownerOrAdmin
                  ||
                  $anonymized
              )
                  ? 'disabled'
                  : ''
              ?>
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

    </section>


    <!-- ===================================================
         PERMANENT ACCOUNT DELETION
         =================================================== -->

    <section class="cleanup-section">

      <article
        class="
          cleanup-card
          cleanup-danger
        "
      >

        <h2>
          Permanently Delete Disposable Account
        </h2>

        <p>
          This removes the user row entirely. Use it only when
          there is no published contribution history that needs
          the stable user ID for provenance.
        </p>


        <div class="cleanup-summary">

          <div>

            <span>
              Published Place Submissions
            </span>

            <strong>
              <?= (int)
                  $publishedPlaceCount
              ?>
            </strong>

          </div>


          <div>

            <span>
              Approved Place Updates
            </span>

            <strong>
              <?= (int)
                  $approvedUpdateCount
              ?>
            </strong>

          </div>

        </div>


        <?php if (
            $publishedPlaceCount > 0
            ||
            $approvedUpdateCount > 0
            ||
            $scoutIdentity
        ): ?>

          <div
            class="
              cleanup-notice
              cleanup-notice--warning
              cleanup-notice--spaced
            "
          >
            Permanent deletion is blocked because this
            account has historical contribution or Scout
            provenance. Use
            <strong>Anonymize Deleted Account</strong>
            instead.
          </div>

        <?php endif; ?>


        <form method="post">

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= (int)
                $userId
            ?>"
          >

          <input
            type="hidden"
            name="action"
            value="permanently_delete_account"
          >


          <label
            class="
              cleanup-check
              cleanup-confirm
            "
          >

            <input
              type="checkbox"
              name="understand_delete"
              value="1"
              required
            >

            <span>
              I understand this permanently removes this
              disposable account and cannot be undone.
            </span>

          </label>


          <div class="cleanup-actions">

            <button
              type="submit"
              class="
                cleanup-button
                cleanup-button--danger
              "
              onclick="
                return confirm(
                  'Permanently delete this disposable account from Llama Scout? This cannot be undone.'
                );
              "
              <?= (
                  $ownerOrAdmin
                  ||
                  $scoutIdentity
                  ||
                  $publishedPlaceCount > 0
                  ||
                  $approvedUpdateCount > 0
              )
                  ? 'disabled'
                  : ''
              ?>
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

    </section>


  <?php endif; ?>


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
