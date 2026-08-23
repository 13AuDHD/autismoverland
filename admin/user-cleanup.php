<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   OWNER TEST ACCOUNT TOOLS

   This page exists for controlled pre-launch / test cleanup.

   RESET:
     Keeps account identity, login, email verification,
     password, username, display name, and basic profile.

   DELETE:
     Removes a normal test account only when doing so will not
     erase published Place history or protected staff / Scout
     identities.

   Stripe safety:
     Remote Stripe cleanup is permitted automatically only
     when the configured secret key is a Stripe TEST key.
     Live Stripe billing records must never be silently
     destroyed by a test-reset tool.
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


if (
    !$owner
) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );
}


$ownerId =
    (int)
    $owner[
        'id'
    ];


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
        (string)
        $value,
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
    int $userId,
    string $extraWhere = ''
): int {

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


    $sql =
        'DELETE FROM `'
        .
        $table
        .
        '` WHERE user_id = ?';


    if (
        $extraWhere !== ''
    ) {

        $sql .=
            ' AND '
            .
            $extraWhere;
    }


    $stmt =
        $db->prepare(
            $sql
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
              ON ur.role_id =
                 r.id

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


function cleanup_user_has_scout_profile(
    PDO $db,
    int $userId
): bool {

    if (
        $userId < 1
        ||
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


function cleanup_user_is_protected(
    array $roles
): bool {

    foreach (
        [
            'owner',
            'admin',
            'scout',
            'master-scout',
            'master_scout',
        ]
        as
        $protectedRole
    ) {

        if (
            in_array(
                $protectedRole,
                $roles,
                true
            )
        ) {

            return true;
        }
    }


    return false;
}


function cleanup_stripe_is_test_mode(): bool {

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


function cleanup_stripe_test_customer(
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
        !cleanup_stripe_is_test_mode()
    ) {

        throw new RuntimeException(
            'This account has Stripe billing data, but Llama Scout is not using a Stripe test secret key. The test-account reset will not modify live Stripe billing.'
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


            /*
             * A stale / already-deleted test object should not
             * prevent the local test account from being reset.
             */
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


function cleanup_membership_columns(
    PDO $db,
    int $userId
): void {

    $assignments = [];

    $params = [];


    $columnValues = [

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


    foreach (
        $columnValues as
        $column =>
        $value
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


    if (
        !$assignments
    ) {

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
                    ",\n                ",
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


/* =========================================================
   TARGET
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


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            email,
            username,
            display_name,
            status,
            email_verified_at,
            created_at,

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


$managedUser =
    $stmt->fetch(
        PDO::FETCH_ASSOC
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


if (
    $userId ===
    $ownerId
) {

    http_response_code(
        403
    );

    exit(
        'Your Owner account cannot be managed with the test-account cleanup tool.'
    );
}


$managedRoles =
    cleanup_user_roles(
        $db,
        $userId
    );


$protectedUser =
    cleanup_user_is_protected(
        $managedRoles
    )
    ||
    cleanup_user_has_scout_profile(
        $db,
        $userId
    );


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
    ] ===
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


        /*
         * Roles are reloaded immediately before destructive
         * work so protection cannot be bypassed with a stale
         * page.
         */

        $managedRoles =
            cleanup_user_roles(
                $db,
                $userId
            );


        $protectedUser =
            cleanup_user_is_protected(
                $managedRoles
            )
            ||
            cleanup_user_has_scout_profile(
                $db,
                $userId
            );


        if (
            $protectedUser
        ) {

            $error =
                'Owner, Admin, Scout, Master Scout, and Scout-onboarding accounts cannot be reset or deleted with this test-account tool.';

        } else {

            $confirmation =
                trim(
                    (string) (
                        $_POST[
                            'confirm_username'
                        ]
                        ?? ''
                    )
                );


            $expectedConfirmation =
                (string) (
                    $managedUser[
                        'username'
                    ]
                    ?:
                    $managedUser[
                        'email'
                    ]
                );


            if (
                $confirmation !==
                $expectedConfirmation
            ) {

                $error =
                    'The confirmation value does not match this account.';

            } elseif (
                $action ===
                'reset_membership_test'
            ) {

                try {

                    /*
                     * Stripe first. If remote TEST cleanup
                     * fails, local billing state remains intact.
                     */

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


                    cleanup_membership_columns(
                        $db,
                        $userId
                    );


                    if (
                        cleanup_table_exists(
                            $db,
                            'membership_grants'
                        )
                        &&
                        cleanup_column_exists(
                            $db,
                            'membership_grants',
                            'user_id'
                        )
                    ) {

                        $stmt =
                            $db->prepare(
                                '
                                DELETE FROM membership_grants
                                WHERE user_id = ?
                                '
                            );


                        $stmt->execute([
                            $userId
                        ]);
                    }


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
                    ) {

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
                    ) {

                        /*
                         * Approved updates are published history.
                         * Reset may clear pending/rejected/draft
                         * test workflow rows, but never approved
                         * contributions.
                         */

                        if (
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


                    llama_membership_audit(
                        $db,
                        $ownerId,
                        'test_account_membership_reset',
                        'user',
                        $userId,
                        [
                            'username' =>
                                $managedUser[
                                    'username'
                                ]
                                ?? null,

                            'email' =>
                                $managedUser[
                                    'email'
                                ]
                                ?? null,

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

                            'clear_saved_places' =>
                                $clearSaved,

                            'clear_unpublished_submissions' =>
                                $clearUnpublishedSubmissions,

                            'clear_place_updates' =>
                                $clearPlaceUpdates,
                        ]
                    );


                    $db->commit();


                    $message =
                        'Test membership state reset. The account can be reused for another membership checkout.';


                    /*
                     * Reload billing fields.
                     */

                    $stmt =
                        $db->prepare(
                            '
                            SELECT
                                id,
                                email,
                                username,
                                display_name,
                                status,
                                email_verified_at,
                                created_at,

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


                    $managedUser =
                        $stmt->fetch(
                            PDO::FETCH_ASSOC
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
                        'Llama Scout test account reset error for user #'
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


            } elseif (
                $action ===
                'permanently_delete_test_user'
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
                        $publishedPlaces > 0
                    ) {

                        throw new RuntimeException(
                            'This account has one or more submissions already published as Places. Permanent deletion is blocked so published provenance is not erased.'
                        );
                    }


                    if (
                        $approvedUpdates > 0
                    ) {

                        throw new RuntimeException(
                            'This account has approved Place Update history. Permanent deletion is blocked so published contribution provenance is not erased.'
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


                    /*
                     * Remote Stripe TEST cleanup happens before
                     * the local transaction.
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
                     * Explicitly remove known account-owned
                     * records that may not have cascading
                     * foreign keys.
                     */

                    foreach (
                        [
                            'user_saved_places',
                            'saved_places',
                            'membership_grants',
                            'place_update_submissions',
                            'email_verifications',
                            'password_resets',
                        ]
                        as
                        $table
                    ) {

                        cleanup_delete_by_user_id(
                            $db,
                            $table,
                            $userId
                        );
                    }


                    /*
                     * Unpublished submissions are safe to
                     * remove. Published submissions were
                     * blocked above.
                     */

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
                    ) {

                        $stmt =
                            $db->prepare(
                                '
                                DELETE FROM place_submissions
                                WHERE user_id = ?
                                '
                            );


                        $stmt->execute([
                            $userId
                        ]);
                    }


                    /*
                     * User-role rows are identity-owned.
                     */

                    cleanup_delete_by_user_id(
                        $db,
                        'user_roles',
                        $userId
                    );


                    /*
                     * Delete the user last. Any remaining
                     * restrictive FK will safely block this
                     * operation instead of silently deleting
                     * unknown site history.
                     */

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
                        !==
                        1
                    ) {

                        throw new RuntimeException(
                            'The user record could not be deleted.'
                        );
                    }


                    $db->commit();


                    $deleted =
                        true;


                    $message =
                        'The test user was permanently removed from Llama Scout.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    error_log(
                        'Llama Scout permanent test-user deletion error for user #'
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


            } else {

                $error =
                    'Unknown test-account action.';
            }
        }
    }
}


/* =========================================================
   SUMMARY
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


$confirmationText =
    !$deleted
        ? (string) (
            $managedUser[
                'username'
            ]
            ?:
            $managedUser[
                'email'
            ]
        )
        : '';


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
    Test Account Tools | Llama Scout Basecamp
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


  <style>

    .cleanup-main {
      width: min(100%, 980px);
      margin: 0 auto;
      padding: 34px 18px 80px;
    }


    .cleanup-back {
      margin-bottom: 28px;
    }


    .cleanup-section {
      margin-top: 24px;
    }


    .cleanup-card {
      padding: 22px;
      border: 1px solid rgba(23,40,34,.12);
      border-radius: 16px;
      background: rgba(255,255,255,.84);
    }


    .cleanup-card h2,
    .cleanup-card h3 {
      margin-top: 0;
    }


    .cleanup-card p {
      line-height: 1.55;
    }


    .cleanup-summary {
      display: grid;
      grid-template-columns:
        repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 16px;
    }


    .cleanup-summary > div {
      padding: 12px 14px;
      border-radius: 10px;
      background: rgba(23,40,34,.045);
    }


    .cleanup-summary span {
      display: block;
      margin-bottom: 3px;
      font-size: .7rem;
      opacity: .62;
    }


    .cleanup-summary strong {
      overflow-wrap: anywhere;
    }


    .cleanup-notice {
      margin-bottom: 16px;
      padding: 13px 15px;
      border-radius: 10px;
      line-height: 1.5;
    }


    .cleanup-notice--success {
      border: 1px solid rgba(53,110,78,.22);
      background: rgba(53,110,78,.12);
    }


    .cleanup-notice--error {
      border: 1px solid rgba(139,55,55,.24);
      background: rgba(139,55,55,.11);
    }


    .cleanup-notice--warning {
      border: 1px solid rgba(175,126,31,.22);
      background: rgba(175,126,31,.10);
    }


    .cleanup-checks {
      display: grid;
      gap: 9px;
      margin: 16px 0;
    }


    .cleanup-check {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      line-height: 1.45;
    }


    .cleanup-check input {
      margin-top: 3px;
    }


    .cleanup-field {
      margin-top: 15px;
    }


    .cleanup-field label {
      display: block;
      margin-bottom: 6px;
      font-size: .78rem;
      font-weight: 800;
    }


    .cleanup-field input[type="text"] {
      width: 100%;
      min-height: 44px;
      padding: 9px 11px;
      border: 1px solid rgba(23,40,34,.16);
      border-radius: 8px;
      background: #fff;
      color: inherit;
      font: inherit;
    }


    .cleanup-help {
      margin-top: 6px;
      font-size: .76rem;
      line-height: 1.45;
      opacity: .67;
    }


    .cleanup-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 9px;
      margin-top: 16px;
    }


    .cleanup-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      min-height: 42px;
      padding: 9px 14px;
      border: 0;
      border-radius: 8px;
      background: #172822;
      color: #fff;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
    }


    .cleanup-button--danger {
      background: #7d2929;
    }


    .cleanup-danger {
      border-color: rgba(139,55,55,.24);
      background: rgba(255,251,251,.92);
    }


    code {
      overflow-wrap: anywhere;
    }


    @media (max-width: 680px) {

      .cleanup-summary {
        grid-template-columns: 1fr;
      }

    }

  </style>

</head>


<body>


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="cleanup-main">


  <section class="admin-intro">

    <p class="admin-eyebrow">
      Basecamp Â· Owner Tools
    </p>

    <h1>
      Test Account Tools
    </h1>

    <p>
      Reset a reusable membership-testing account or permanently
      remove a disposable test user without touching published
      Llama Scout contribution history.
    </p>

    <p class="admin-role-line">
      <?= e(
          $primaryRoleIcon
      ) ?>
      <?= e(
          $primaryRoleLabel
      ) ?>
    </p>

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

    <div class="cleanup-notice cleanup-notice--success">
      <?= e(
          $message
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="cleanup-notice cleanup-notice--error">
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
        User Removed
      </h2>

      <p>
        The account no longer exists in Llama Scout. You can
        return to the Users list.
      </p>

      <a
        href="users.php"
        class="cleanup-button"
      >
        View Users
      </a>

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


      <div class="cleanup-summary">

        <div>
          <span>Username</span>
          <strong>
            <?= e(
                $managedUser[
                    'username'
                ]
                ?? 'None'
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
          <span>Membership Status</span>
          <strong>
            <?= e(
                $managedUser[
                    'membership_status'
                ]
                ?? 'none'
            ) ?>
          </strong>
        </div>

        <div>
          <span>Membership Interval</span>
          <strong>
            <?= e(
                $managedUser[
                    'membership_interval'
                ]
                ?? 'none'
            ) ?>
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
        $protectedUser
    ): ?>

      <div
        class="
          cleanup-notice
          cleanup-notice--warning
        "
        style="margin-top:18px;"
      >
        This account has a protected Owner, Admin, Scout, or
        Master Scout role. Test reset and permanent deletion
        are disabled for this account.
      </div>

    <?php endif; ?>


    <section class="cleanup-section">

      <article class="cleanup-card">

        <h2>
          Reset Membership Test
        </h2>

        <p>
          Use this after a Stripe test checkout so the same
          verified account can go through registration-free
          membership testing again.
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

            Existing Stripe test subscription/customer objects
            linked to this account will be canceled/deleted
            before local membership state is cleared.

          <?php else: ?>

            If this account contains Stripe billing IDs, reset
            will refuse to continue. Live billing is never
            silently destroyed by this tool.

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
                Clear Saved Places for a completely fresh
                membership-access test.
              </span>

            </label>


            <label class="cleanup-check">

              <input
                type="checkbox"
                name="clear_unpublished_submissions"
                value="1"
              >

              <span>
                Delete this user's unpublished new-Place
                submissions. Published Places are never
                removed by reset.
              </span>

            </label>


            <label class="cleanup-check">

              <input
                type="checkbox"
                name="clear_place_updates"
                value="1"
              >

              <span>
                Delete this user's non-approved Place Update
                submissions. Approved contribution history is
                always preserved.
              </span>

            </label>

          </div>


          <div class="cleanup-field">

            <label>
              Confirm Account
            </label>

            <input
              type="text"
              name="confirm_username"
              autocomplete="off"
              placeholder="<?= e(
                  $confirmationText
              ) ?>"
              required
            >

            <div class="cleanup-help">
              Type exactly:
              <strong>
                <?= e(
                    $confirmationText
                ) ?>
              </strong>
            </div>

          </div>


          <div class="cleanup-actions">

            <button
              type="submit"
              class="cleanup-button"
              <?= $protectedUser
                  ? 'disabled'
                  : ''
              ?>
            >
              <i
                class="fa-solid fa-rotate-left"
                aria-hidden="true"
              ></i>

              Reset Test Account
            </button>

          </div>

        </form>

      </article>

    </section>


    <section class="cleanup-section">

      <article
        class="
          cleanup-card
          cleanup-danger
        "
      >

        <h2>
          Permanently Delete Test User
        </h2>

        <p>
          This removes the user identity and disposable
          account-owned test records. It is deliberately
          blocked when Llama Scout detects published Place
          provenance or approved Place Update history.
        </p>


        <div class="cleanup-summary">

          <div>
            <span>Published Place Submissions</span>
            <strong>
              <?= (int)
                  $publishedPlaceCount
              ?>
            </strong>
          </div>

          <div>
            <span>Approved Place Updates</span>
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
        ): ?>

          <div
            class="
              cleanup-notice
              cleanup-notice--warning
            "
            style="margin-top:16px;"
          >
            Permanent deletion is blocked because this account
            has contribution history that Llama Scout should
            preserve.
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
            value="permanently_delete_test_user"
          >


          <div class="cleanup-field">

            <label>
              Confirm Account
            </label>

            <input
              type="text"
              name="confirm_username"
              autocomplete="off"
              placeholder="<?= e(
                  $confirmationText
              ) ?>"
              required
            >

            <div class="cleanup-help">
              Type exactly:
              <strong>
                <?= e(
                    $confirmationText
                ) ?>
              </strong>
            </div>

          </div>


          <label
            class="cleanup-check"
            style="margin-top:15px;"
          >

            <input
              type="checkbox"
              name="understand_delete"
              value="1"
              required
            >

            <span>
              I understand this permanently removes the test
              account and cannot be undone.
            </span>

          </label>


          <div class="cleanup-actions">

            <button
              type="submit"
              class="
                cleanup-button
                cleanup-button--danger
              "
              <?= (
                  $protectedUser
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
                class="fa-solid fa-trash"
                aria-hidden="true"
              ></i>

              Permanently Delete Test User
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
