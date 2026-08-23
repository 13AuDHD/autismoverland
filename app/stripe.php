<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/memberships.php';



/* =========================================================
   LLAMA SCOUT STRIPE SERVICE

   Private Stripe configuration owns credentials.

   Membership plan pricing and Stripe Price IDs are owned by
   the membership database.

   Legacy monthly_price_id / annual_price_id config values are
   recognized only as migration fallbacks.
   ========================================================= */


/* =========================================================
   STRIPE CONFIG
   ========================================================= */

function llama_stripe_config(): array
{
    static $config = null;

    if (
        $config !== null
    ) {
        return $config;
    }


    $configPath =
        dirname(__DIR__, 2)
        . '/private/stripe.php';


    $libraryPath =
        dirname(__DIR__, 2)
        . '/private/stripe-php/init.php';


    if (
        !is_file(
            $configPath
        )
    ) {
        throw new RuntimeException(
            'Private Stripe configuration is missing.'
        );
    }


    if (
        !is_file(
            $libraryPath
        )
    ) {
        throw new RuntimeException(
            'Stripe PHP library is missing.'
        );
    }


    require_once
        $libraryPath;


    $config =
        require
        $configPath;


    if (
        !is_array(
            $config
        )
    ) {
        throw new RuntimeException(
            'Private Stripe configuration is invalid.'
        );
    }


    if (
        empty(
            $config[
                'secret_key'
            ]
        )
    ) {
        throw new RuntimeException(
            'Stripe configuration is missing: secret_key'
        );
    }


    return
        $config;
}


/* =========================================================
   WEBHOOK SECRET
   ========================================================= */

function llama_stripe_webhook_secret(): string
{
    $config =
        llama_stripe_config();


    $secret =
        trim(
            (string) (
                $config[
                    'webhook_secret'
                ]
                ?? ''
            )
        );


    if (
        $secret === ''
    ) {
        throw new RuntimeException(
            'Stripe webhook secret is missing.'
        );
    }


    return
        $secret;
}


/* =========================================================
   STRIPE CLIENT
   ========================================================= */

function llama_stripe_client(): \Stripe\StripeClient
{
    static $client = null;


    if (
        $client
        instanceof
        \Stripe\StripeClient
    ) {
        return
            $client;
    }


    $config =
        llama_stripe_config();


    $client =
        new \Stripe\StripeClient(
            $config[
                'secret_key'
            ]
        );


    return
        $client;
}


/* =========================================================
   LEGACY PRIVATE-CONFIG PRICE LOOKUP

   New checkout code MUST use membership_plans.stripe_price_id.

   This helper remains temporarily for backward compatibility
   with older callers during migration.
   ========================================================= */

function llama_stripe_price_id(
    string $interval
): string {

    $config =
        llama_stripe_config();


    $key =
        match (
            $interval
        ) {

            'monthly' =>
                'monthly_price_id',

            'annual' =>
                'annual_price_id',

            default =>
                throw new InvalidArgumentException(
                    'Invalid membership interval.'
                ),

        };


    $priceId =
        trim(
            (string) (
                $config[
                    $key
                ]
                ?? ''
            )
        );


    if (
        $priceId === ''
    ) {
        throw new RuntimeException(
            'Legacy Stripe price configuration is not available for '
            .
            $interval
            .
            '.'
        );
    }


    return
        $priceId;
}


/* =========================================================
   MEMBERSHIP INTERVAL FROM STRIPE PRICE

   Primary source:
       membership_plans.stripe_price_id

   Migration fallback:
       private monthly_price_id / annual_price_id

   Metadata remains a separate fallback inside subscription
   synchronization.
   ========================================================= */

function llama_membership_interval_from_price(
    PDO $db,
    ?string $priceId
): ?string {

    $priceId =
        trim(
            (string)
            $priceId
        );


    if (
        $priceId === ''
    ) {
        return null;
    }


    /*
     * Permanent source:
     *
     * membership_plan_prices contains every current and
     * historical Stripe Price ID ever assigned to a Llama
     * Scout membership plan.
     *
     * We intentionally do not run schema migration here.
     * Webhooks must remain read/sync operations.
     */

    try {

        $price =
            llama_membership_price_by_stripe_price_id(
                $db,
                $priceId
            );


        if (
            $price
        ) {

            $interval =
                strtolower(
                    trim(
                        (string) (
                            $price[
                                'interval_slug'
                            ]
                            ?? ''
                        )
                    )
                );


            if (
                in_array(
                    $interval,
                    [
                        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
                        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
                    ],
                    true
                )
            ) {

                return
                    $interval;

            }

        }

    } catch (
        Throwable $exception
    ) {

        /*
         * Migration safety:
         *
         * If the versioned table is not available yet, continue
         * to compatibility fallbacks below instead of breaking
         * webhook processing.
         */

        error_log(
            'Llama Scout historical Stripe Price lookup failed for '
            .
            $priceId
            .
            ': '
            .
            $exception
                ->getMessage()
        );

    }


    /*
     * Compatibility shadow:
     *
     * During migration, membership_plans still contains the
     * current Stripe Price ID. This catches a deployment where
     * the service file changed before price history initialized.
     */

    try {

        $stmt =
            $db->prepare(
                '
                SELECT
                    interval_slug

                FROM membership_plans

                WHERE stripe_price_id = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $priceId
        ]);


        $interval =
            strtolower(
                trim(
                    (string) (
                        $stmt->fetchColumn()
                        ?: ''
                    )
                )
            );


        if (
            in_array(
                $interval,
                [
                    LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
                    LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
                ],
                true
            )
        ) {

            return
                $interval;

        }

    } catch (
        Throwable
    ) {

        /*
         * Continue to old private-config migration fallback.
         */

    }


    /*
     * Old private configuration is now only a final migration
     * fallback for subscriptions created before the database
     * catalog became authoritative.
     */

    $config =
        llama_stripe_config();


    $legacyMonthly =
        trim(
            (string) (
                $config[
                    'monthly_price_id'
                ]
                ?? ''
            )
        );


    if (
        $legacyMonthly !== ''
        &&
        hash_equals(
            $legacyMonthly,
            $priceId
        )
    ) {

        return
            LLAMA_MEMBERSHIP_INTERVAL_MONTHLY;

    }


    $legacyAnnual =
        trim(
            (string) (
                $config[
                    'annual_price_id'
                ]
                ?? ''
            )
        );


    if (
        $legacyAnnual !== ''
        &&
        hash_equals(
            $legacyAnnual,
            $priceId
        )
    ) {

        return
            LLAMA_MEMBERSHIP_INTERVAL_ANNUAL;

    }


    return null;
}


/* =========================================================
   MEMBERSHIP STATUS FROM STRIPE
   ========================================================= */

function llama_membership_status_from_stripe(
    ?string $stripeStatus
): string {

    return match (
        (string)
        $stripeStatus
    ) {

        'active' =>
            'active',

        'trialing' =>
            'trialing',

        'past_due' =>
            'past_due',

        'canceled',
        'unpaid',
        'incomplete_expired' =>
            'canceled',

        'incomplete',
        'paused' =>
            'past_due',

        default =>
            'none',

    };
}


/* =========================================================
   STRIPE TIMESTAMP
   ========================================================= */

function llama_stripe_timestamp(
    mixed $timestamp
): ?string {

    if (
        $timestamp === null
        ||
        $timestamp === ''
        ||
        !is_numeric(
            $timestamp
        )
    ) {
        return null;
    }


    return gmdate(
        'Y-m-d H:i:s',
        (int)
        $timestamp
    );
}


/* =========================================================
   SUBSCRIPTION PERIOD END
   ========================================================= */

function llama_subscription_period_end(
    object $subscription
): ?string {

    if (
        isset(
            $subscription
                ->current_period_end
        )
        &&
        is_numeric(
            $subscription
                ->current_period_end
        )
    ) {
        return llama_stripe_timestamp(
            $subscription
                ->current_period_end
        );
    }


    $items =
        $subscription
            ->items
            ->data
        ?? [];


    if (
        is_array(
            $items
        )
        &&
        $items
    ) {

        $ends = [];


        foreach (
            $items as
            $item
        ) {

            $end =
                $item
                    ->current_period_end
                ?? null;


            if (
                is_numeric(
                    $end
                )
            ) {
                $ends[] =
                    (int)
                    $end;
            }

        }


        if (
            $ends
        ) {
            return llama_stripe_timestamp(
                max(
                    $ends
                )
            );
        }

    }


    return null;
}


/* =========================================================
   SUBSCRIPTION CANCEL STATE
   ========================================================= */

function llama_subscription_cancel_at_period_end(
    object $subscription
): bool {

    return
        !empty(
            $subscription
                ->cancel_at_period_end
        );
}


/* =========================================================
   SYNC STRIPE SUBSCRIPTION INTO LLAMA SCOUT

   Stripe billing state and Llama Scout access sources remain
   separate.

   Stripe ending does not destroy contribution points.

   Stripe ending does not remove Scout access.

   Complimentary grants remain separate from Stripe state.
   ========================================================= */

function llama_sync_stripe_subscription(
    PDO $db,
    object $subscription,
    ?int $fallbackUserId = null
): int {

    $subscriptionId =
        trim(
            (string) (
                $subscription
                    ->id
                ?? ''
            )
        );


    $customerId =
        trim(
            (string) (
                $subscription
                    ->customer
                ?? ''
            )
        );


    if (
        $subscriptionId === ''
        ||
        $customerId === ''
    ) {
        throw new RuntimeException(
            'Stripe subscription is missing an ID or customer.'
        );
    }


    /* =====================================================
       USER
       ===================================================== */

    $metadataUserId =
        (int) (
            $subscription
                ->metadata
                ->llama_user_id
            ?? 0
        );


    $userId =
        $metadataUserId > 0
            ? $metadataUserId
            : (
                $fallbackUserId
                ?? 0
            );


    if (
        $userId < 1
    ) {

        $lookup =
            $db->prepare(
                '
                SELECT id

                FROM users

                WHERE stripe_subscription_id = ?
                   OR stripe_customer_id = ?

                LIMIT 1
                '
            );


        $lookup->execute([
            $subscriptionId,
            $customerId,
        ]);


        $row =
            $lookup->fetch(
                PDO::FETCH_ASSOC
            );


        $userId =
            (int) (
                $row[
                    'id'
                ]
                ?? 0
            );

    }


    if (
        $userId < 1
    ) {
        throw new RuntimeException(
            'Could not match Stripe subscription to a Llama Scout user.'
        );
    }


    /* =====================================================
       PLAN / INTERVAL
       ===================================================== */

    $firstItem =
        $subscription
            ->items
            ->data[0]
        ?? null;


    $priceId =
        $firstItem
            ->price
            ->id
        ?? null;


    $interval =
        llama_membership_interval_from_price(
            $db,
            $priceId !== null
                ? (string)
                  $priceId
                : null
        );


    /*
     * Checkout writes the selected interval into subscription
     * metadata. This protects synchronization when an older
     * Stripe Price is still attached to an existing customer
     * but is no longer the current catalog Price.
     */

    if (
        !$interval
    ) {

        $metadataInterval =
            strtolower(
                trim(
                    (string) (
                        $subscription
                            ->metadata
                            ->membership_interval
                        ?? ''
                    )
                )
            );


        if (
            in_array(
                $metadataInterval,
                [
                    'monthly',
                    'annual',
                ],
                true
            )
        ) {
            $interval =
                $metadataInterval;
        }

    }


    /*
     * Do not replace a known interval with NULL merely because
     * a very old Stripe subscription lacks current catalog or
     * metadata information.
     */

    if (
        !$interval
    ) {

        $existingStmt =
            $db->prepare(
                '
                SELECT
                    membership_interval

                FROM users

                WHERE id = ?

                LIMIT 1
                '
            );


        $existingStmt->execute([
            $userId
        ]);


        $existingInterval =
            strtolower(
                trim(
                    (string) (
                        $existingStmt
                            ->fetchColumn()
                        ?: ''
                    )
                )
            );


        if (
            in_array(
                $existingInterval,
                [
                    'monthly',
                    'annual',
                ],
                true
            )
        ) {
            $interval =
                $existingInterval;
        }

    }


    /* =====================================================
       STRIPE STATUS
       ===================================================== */

    $membershipStatus =
        llama_membership_status_from_stripe(
            (string) (
                $subscription
                    ->status
                ?? ''
            )
        );


    $startedAt =
        llama_stripe_timestamp(
            $subscription
                ->start_date
            ??
            $subscription
                ->created
            ??
            null
        );


    $endsAt =
        llama_subscription_period_end(
            $subscription
        );


    $cancelAtPeriodEnd =
        llama_subscription_cancel_at_period_end(
            $subscription
        )
            ? 1
            : 0;


    /* =====================================================
       PERSIST BILLING STATE

       This updates Stripe billing state only.

       It does not mutate membership_grants, Scout rank,
       Scout activity, or lifetime contribution points.
       ===================================================== */

    $stmt =
        $db->prepare(
            '
            UPDATE users

            SET
                stripe_customer_id = ?,
                stripe_subscription_id = ?,
                stripe_cancel_at_period_end = ?,
                membership_status = ?,
                membership_interval = ?,

                membership_started_at =
                    COALESCE(
                        membership_started_at,
                        ?
                    ),

                membership_ends_at = ?

            WHERE id = ?
            '
        );


    $stmt->execute([
        $customerId,
        $subscriptionId,
        $cancelAtPeriodEnd,
        $membershipStatus,
        $interval,
        $startedAt,
        $endsAt,
        $userId,
    ]);


    return
        $userId;
}


/* =========================================================
   SCHEDULE PAID SUBSCRIPTION TO END

   Used when a user earns full Scout access while they still
   have a paid Stripe membership.

   We intentionally do NOT cancel immediately. Stripe keeps
   the paid subscription active through the already-paid
   billing period and stops renewal at period end.
   ========================================================= */

function llama_schedule_subscription_end_for_scout(
    PDO $db,
    int $userId
): array {

    if (
        $userId < 1
    ) {
        throw new InvalidArgumentException(
            'A valid user ID is required.'
        );
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                stripe_customer_id,
                stripe_subscription_id,
                stripe_cancel_at_period_end,
                membership_status,
                membership_ends_at

            FROM users

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $account =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$account
    ) {
        throw new RuntimeException(
            'Llama Scout user was not found.'
        );
    }


    $subscriptionId =
        trim(
            (string) (
                $account[
                    'stripe_subscription_id'
                ]
                ?? ''
            )
        );


    if (
        $subscriptionId === ''
    ) {
        return [
            'changed' =>
                false,

            'reason' =>
                'no_subscription',

            'ends_at' =>
                null,
        ];
    }


    $subscription =
        llama_stripe_client()
            ->subscriptions
            ->retrieve(
                $subscriptionId,
                []
            );


    $stripeStatus =
        strtolower(
            trim(
                (string) (
                    $subscription
                        ->status
                    ?? ''
                )
            )
        );


    if (
        in_array(
            $stripeStatus,
            [
                'canceled',
                'unpaid',
                'incomplete_expired',
            ],
            true
        )
    ) {

        llama_sync_stripe_subscription(
            $db,
            $subscription,
            $userId
        );


        return [
            'changed' =>
                false,

            'reason' =>
                'already_ended',

            'ends_at' =>
                llama_subscription_period_end(
                    $subscription
                ),
        ];
    }


    if (
        !empty(
            $subscription
                ->cancel_at_period_end
        )
    ) {

        llama_sync_stripe_subscription(
            $db,
            $subscription,
            $userId
        );


        return [
            'changed' =>
                false,

            'reason' =>
                'already_scheduled',

            'ends_at' =>
                llama_subscription_period_end(
                    $subscription
                ),
        ];
    }


    /*
     * Stripe's standard subscription API supports
     * cancel_at_period_end=true.
     *
     * This does not immediately terminate service and does
     * not create an immediate refund.
     */

    $subscription =
        llama_stripe_client()
            ->subscriptions
            ->update(
                $subscriptionId,
                [
                    'cancel_at_period_end' =>
                        true,
                ]
            );


    /*
     * Sync the returned Stripe object immediately rather
     * than waiting for the webhook. Stripe will also send
     * customer.subscription.updated, and that webhook will
     * safely sync the same state again.
     */

    llama_sync_stripe_subscription(
        $db,
        $subscription,
        $userId
    );


    return [
        'changed' =>
            true,

        'reason' =>
            'scheduled',

        'ends_at' =>
            llama_subscription_period_end(
                $subscription
            ),
    ];
}
