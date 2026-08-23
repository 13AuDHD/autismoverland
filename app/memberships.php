<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT MEMBERSHIP CATALOG

   Permanent shared data layer for:

   - Monthly / Annual membership plans
   - Base pricing
   - Stripe Product / Price references
   - Scheduled site-wide promotions
   - Stripe Coupon references
   - Complimentary access grants
   - Membership administration audit history

   IMPORTANT:

   Monetary values are stored as integer cents.

   Promotion schedules are stored in UTC database timestamps.

   Paid Stripe subscription state remains separate from
   complimentary grants and Scout access.
   ========================================================= */


/* =========================================================
   CONSTANTS
   ========================================================= */

const LLAMA_MEMBERSHIP_INTERVAL_MONTHLY =
    'monthly';

const LLAMA_MEMBERSHIP_INTERVAL_ANNUAL =
    'annual';


const LLAMA_PROMOTION_DISCOUNT_PERCENT =
    'percent';

const LLAMA_PROMOTION_DISCOUNT_AMOUNT =
    'amount';


const LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY =
    'complimentary';


/* =========================================================
   TABLE / COLUMN HELPERS
   ========================================================= */

function llama_membership_table_exists(
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


/* =========================================================
   ENSURE MEMBERSHIP STORAGE
   ========================================================= */

function llama_ensure_membership_storage(
    PDO $db
): void {

    if (
        $db->inTransaction()
    ) {

        throw new RuntimeException(
            'Membership storage cannot be initialized inside an active transaction.'
        );
    }


    /* =====================================================
       MEMBERSHIP PLANS
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_plans
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            interval_slug VARCHAR(30)
                NOT NULL,

            name VARCHAR(100)
                NOT NULL,

            description TEXT
                NULL,

            currency CHAR(3)
                NOT NULL DEFAULT \'usd\',

            base_price_cents INT UNSIGNED
                NOT NULL,

            stripe_product_id VARCHAR(255)
                NULL,

            stripe_price_id VARCHAR(255)
                NULL,

            is_active TINYINT(1)
                NOT NULL DEFAULT 1,

            sort_order INT
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_membership_plan_interval
                (interval_slug),

            KEY idx_membership_plan_active
                (is_active, sort_order)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       PROMOTIONS

       Promotion itself owns the schedule and public copy.

       Each plan can have its own discount through
       membership_promotion_plans.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_promotions
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            name VARCHAR(150)
                NOT NULL,

            public_label VARCHAR(150)
                NULL,

            public_description TEXT
                NULL,

            starts_at DATETIME
                NOT NULL,

            ends_at DATETIME
                NOT NULL,

            is_enabled TINYINT(1)
                NOT NULL DEFAULT 1,

            created_by BIGINT UNSIGNED
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_membership_promotion_window
                (
                    is_enabled,
                    starts_at,
                    ends_at
                ),

            KEY idx_membership_promotion_created_by
                (created_by),

            CONSTRAINT fk_membership_promotion_created_by
                FOREIGN KEY (created_by)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       PROMOTION → PLAN RULES

       This allows:

       Holiday Sale
       Monthly = 20% off
       Annual  = 25% off

       without creating separate promotion records.

       stripe_coupon_id is the Stripe coupon that actually
       applies the financial discount at checkout.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_promotion_plans
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            promotion_id BIGINT UNSIGNED
                NOT NULL,

            plan_id BIGINT UNSIGNED
                NOT NULL,

            discount_type VARCHAR(20)
                NOT NULL,

            discount_value INT UNSIGNED
                NOT NULL,

            stripe_coupon_id VARCHAR(255)
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_membership_promotion_plan
                (
                    promotion_id,
                    plan_id
                ),

            KEY idx_membership_promotion_plan_plan
                (plan_id),

            CONSTRAINT fk_membership_promotion_plan_promotion
                FOREIGN KEY (promotion_id)
                REFERENCES membership_promotions(id)
                ON DELETE CASCADE,

            CONSTRAINT fk_membership_promotion_plan_plan
                FOREIGN KEY (plan_id)
                REFERENCES membership_plans(id)
                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       COMPLIMENTARY MEMBERSHIP GRANTS

       Complimentary access is intentionally separate from
       users.membership_status.

       A user may simultaneously have:

       - a Stripe subscription
       - a complimentary grant
       - Scout access

       without one source overwriting another.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_grants
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            user_id BIGINT UNSIGNED
                NOT NULL,

            grant_type VARCHAR(30)
                NOT NULL DEFAULT \'complimentary\',

            starts_at DATETIME
                NOT NULL,

            ends_at DATETIME
                NOT NULL,

            reason VARCHAR(255)
                NULL,

            notes TEXT
                NULL,

            granted_by BIGINT UNSIGNED
                NULL,

            revoked_at DATETIME
                NULL,

            revoked_by BIGINT UNSIGNED
                NULL,

            revoke_reason VARCHAR(255)
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_membership_grant_user
                (
                    user_id,
                    grant_type,
                    starts_at,
                    ends_at,
                    revoked_at
                ),

            KEY idx_membership_grant_granted_by
                (granted_by),

            KEY idx_membership_grant_revoked_by
                (revoked_by),

            CONSTRAINT fk_membership_grant_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE,

            CONSTRAINT fk_membership_grant_granted_by
                FOREIGN KEY (granted_by)
                REFERENCES users(id)
                ON DELETE SET NULL,

            CONSTRAINT fk_membership_grant_revoked_by
                FOREIGN KEY (revoked_by)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       OWNER AUDIT HISTORY

       Pricing and access changes should remain attributable.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_audit_log
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            actor_user_id BIGINT UNSIGNED
                NULL,

            action VARCHAR(100)
                NOT NULL,

            subject_type VARCHAR(50)
                NOT NULL,

            subject_id BIGINT UNSIGNED
                NULL,

            details_json JSON
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_membership_audit_actor
                (actor_user_id),

            KEY idx_membership_audit_subject
                (
                    subject_type,
                    subject_id
                ),

            KEY idx_membership_audit_created
                (created_at),

            CONSTRAINT fk_membership_audit_actor
                FOREIGN KEY (actor_user_id)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    llama_seed_membership_plans(
        $db
    );
}


/* =========================================================
   DEFAULT PLAN SEED

   These preserve the pricing currently displayed by the
   website.

   Once the Owner Membership panel is active, future pricing
   changes happen through the database rather than code.
   ========================================================= */

function llama_seed_membership_plans(
    PDO $db
): void {

    $defaults = [

        [
            'interval' =>
                LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,

            'name' =>
                'Monthly',

            'description' =>
                'Full Llama Scout access billed monthly.',

            'price_cents' =>
                699,

            'sort_order' =>
                10,
        ],

        [
            'interval' =>
                LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,

            'name' =>
                'Annual',

            'description' =>
                'Full Llama Scout access billed annually.',

            'price_cents' =>
                5999,

            'sort_order' =>
                20,
        ],

    ];


    $stmt =
        $db->prepare(
            '
            INSERT INTO membership_plans
            (
                interval_slug,
                name,
                description,
                currency,
                base_price_cents,
                is_active,
                sort_order
            )

            VALUES
            (
                ?,
                ?,
                ?,
                \'usd\',
                ?,
                1,
                ?
            )

            ON DUPLICATE KEY UPDATE
                interval_slug =
                    VALUES(interval_slug)
            '
        );


    foreach (
        $defaults as
        $plan
    ) {

        $stmt->execute([
            $plan[
                'interval'
            ],
            $plan[
                'name'
            ],
            $plan[
                'description'
            ],
            $plan[
                'price_cents'
            ],
            $plan[
                'sort_order'
            ],
        ]);

    }
}


/* =========================================================
   PLAN LIST
   ========================================================= */

function llama_membership_plans(
    PDO $db,
    bool $activeOnly = true
): array {

    $sql =
        '
        SELECT
            id,
            interval_slug,
            name,
            description,
            currency,
            base_price_cents,
            stripe_product_id,
            stripe_price_id,
            is_active,
            sort_order,
            created_at,
            updated_at

        FROM membership_plans
        ';


    if (
        $activeOnly
    ) {

        $sql .=
            '
            WHERE is_active = 1
            ';

    }


    $sql .=
        '
        ORDER BY
            sort_order ASC,
            id ASC
        ';


    return
        $db
            ->query(
                $sql
            )
            ->fetchAll(
                PDO::FETCH_ASSOC
            );
}


/* =========================================================
   PLAN BY INTERVAL
   ========================================================= */

function llama_membership_plan_by_interval(
    PDO $db,
    string $interval,
    bool $activeOnly = false
): ?array {

    $interval =
        strtolower(
            trim(
                $interval
            )
        );


    if (
        !in_array(
            $interval,
            [
                LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
                LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
            ],
            true
        )
    ) {

        return null;

    }


    $sql =
        '
        SELECT
            id,
            interval_slug,
            name,
            description,
            currency,
            base_price_cents,
            stripe_product_id,
            stripe_price_id,
            is_active,
            sort_order,
            created_at,
            updated_at

        FROM membership_plans

        WHERE interval_slug = ?
        ';


    if (
        $activeOnly
    ) {

        $sql .=
            '
            AND is_active = 1
            ';

    }


    $sql .=
        '
        LIMIT 1
        ';


    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute([
        $interval
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
   ACTIVE PROMOTION FOR PLAN

   At most one promotion should be used for a plan at a time.

   If multiple promotion windows accidentally overlap, the
   newest-starting promotion wins deterministically.
   ========================================================= */

function llama_membership_active_promotion_for_plan(
    PDO $db,
    int $planId,
    ?string $at = null
): ?array {

    if (
        $planId < 1
    ) {

        return null;

    }


    $at =
        $at
        ?:
        gmdate(
            'Y-m-d H:i:s'
        );


    $stmt =
        $db->prepare(
            '
            SELECT
                mp.id
                    AS promotion_id,

                mp.name
                    AS promotion_name,

                mp.public_label,

                mp.public_description,

                mp.starts_at,

                mp.ends_at,

                mp.is_enabled,

                mpp.discount_type,

                mpp.discount_value,

                mpp.stripe_coupon_id

            FROM membership_promotions mp

            INNER JOIN membership_promotion_plans mpp
                ON mpp.promotion_id = mp.id

            WHERE mpp.plan_id = ?

              AND mp.is_enabled = 1

              AND mp.starts_at <= ?

              AND mp.ends_at > ?

            ORDER BY
                mp.starts_at DESC,
                mp.id DESC

            LIMIT 1
            '
        );


    $stmt->execute([
        $planId,
        $at,
        $at,
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
   CALCULATE PROMOTION PRICE
   ========================================================= */

function llama_membership_discounted_price_cents(
    int $basePriceCents,
    string $discountType,
    int $discountValue
): int {

    $basePriceCents =
        max(
            0,
            $basePriceCents
        );


    $discountValue =
        max(
            0,
            $discountValue
        );


    if (
        $discountType ===
        LLAMA_PROMOTION_DISCOUNT_PERCENT
    ) {

        $percent =
            min(
                100,
                $discountValue
            );


        $discount =
            (int)
            round(
                $basePriceCents
                *
                (
                    $percent
                    /
                    100
                )
            );


        return
            max(
                0,
                $basePriceCents
                -
                $discount
            );

    }


    if (
        $discountType ===
        LLAMA_PROMOTION_DISCOUNT_AMOUNT
    ) {

        return
            max(
                0,
                $basePriceCents
                -
                $discountValue
            );

    }


    return
        $basePriceCents;
}


/* =========================================================
   COMPLETE PLAN OFFER

   This is the main read API public/account/checkout pages
   should use.

   It returns both regular and currently-effective pricing.
   ========================================================= */

function llama_membership_plan_offer(
    PDO $db,
    string $interval,
    ?string $at = null
): ?array {

    $plan =
        llama_membership_plan_by_interval(
            $db,
            $interval,
            true
        );


    if (
        !$plan
    ) {

        return null;

    }


    $basePrice =
        (int)
        $plan[
            'base_price_cents'
        ];


    $promotion =
        llama_membership_active_promotion_for_plan(
            $db,
            (int)
            $plan[
                'id'
            ],
            $at
        );


    $effectivePrice =
        $basePrice;


    if (
        $promotion
    ) {

        $effectivePrice =
            llama_membership_discounted_price_cents(
                $basePrice,
                (string)
                $promotion[
                    'discount_type'
                ],
                (int)
                $promotion[
                    'discount_value'
                ]
            );

    }


    return [
        'plan' =>
            $plan,

        'promotion' =>
            $promotion,

        'base_price_cents' =>
            $basePrice,

        'effective_price_cents' =>
            $effectivePrice,

        'on_sale' =>
            $promotion !== null
            &&
            $effectivePrice
            <
            $basePrice,

        'stripe_coupon_id' =>
            $promotion[
                'stripe_coupon_id'
            ]
            ?? null,
    ];
}


/* =========================================================
   ALL ACTIVE OFFERS
   ========================================================= */

function llama_membership_offers(
    PDO $db,
    ?string $at = null
): array {

    $offers = [];


    foreach (
        [
            LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
            LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
        ]
        as
        $interval
    ) {

        $offer =
            llama_membership_plan_offer(
                $db,
                $interval,
                $at
            );


        if (
            $offer
        ) {

            $offers[
                $interval
            ] =
                $offer;

        }

    }


    return
        $offers;
}


/* =========================================================
   FORMAT MONEY
   ========================================================= */

function llama_membership_format_money(
    int $cents,
    string $currency = 'usd'
): string {

    $currency =
        strtolower(
            trim(
                $currency
            )
        );


    $amount =
        number_format(
            $cents
            /
            100,
            2,
            '.',
            ','
        );


    return match (
        $currency
    ) {

        'usd' =>
            '$'
            .
            $amount,

        default =>
            strtoupper(
                $currency
            )
            .
            ' '
            .
            $amount,

    };
}


/* =========================================================
   PROMOTION STATUS
   ========================================================= */

function llama_membership_promotion_status(
    array $promotion,
    ?string $at = null
): string {

    if (
        empty(
            $promotion[
                'is_enabled'
            ]
        )
    ) {

        return 'disabled';

    }


    $now =
        strtotime(
            $at
            ?:
            gmdate(
                'Y-m-d H:i:s'
            )
        );


    $starts =
        strtotime(
            (string) (
                $promotion[
                    'starts_at'
                ]
                ?? ''
            )
        );


    $ends =
        strtotime(
            (string) (
                $promotion[
                    'ends_at'
                ]
                ?? ''
            )
        );


    if (
        $now === false
        ||
        $starts === false
        ||
        $ends === false
    ) {

        return 'invalid';

    }


    if (
        $now < $starts
    ) {

        return 'scheduled';

    }


    if (
        $now >= $ends
    ) {

        return 'ended';

    }


    return 'live';
}


/* =========================================================
   COMPLIMENTARY DURATION PRESETS
   ========================================================= */

function llama_membership_grant_duration_options(): array {

    return [

        '24h' => [
            'label' =>
                '24 Hours',

            'modify' =>
                '+24 hours',
        ],

        '1w' => [
            'label' =>
                '1 Week',

            'modify' =>
                '+1 week',
        ],

        '2w' => [
            'label' =>
                '2 Weeks',

            'modify' =>
                '+2 weeks',
        ],

        '1m' => [
            'label' =>
                '1 Month',

            'modify' =>
                '+1 month',
        ],

        '3m' => [
            'label' =>
                '3 Months',

            'modify' =>
                '+3 months',
        ],

        '6m' => [
            'label' =>
                '6 Months',

            'modify' =>
                '+6 months',
        ],

        '1y' => [
            'label' =>
                '1 Year',

            'modify' =>
                '+1 year',
        ],

    ];
}


/* =========================================================
   CALCULATE GRANT END
   ========================================================= */

function llama_membership_grant_end(
    string $durationKey,
    ?DateTimeImmutable $startsAt = null
): DateTimeImmutable {

    $options =
        llama_membership_grant_duration_options();


    if (
        !isset(
            $options[
                $durationKey
            ]
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid complimentary membership duration.'
        );

    }


    $startsAt =
        $startsAt
        ?:
        new DateTimeImmutable(
            'now',
            new DateTimeZone(
                'UTC'
            )
        );


    return
        $startsAt
            ->modify(
                (string)
                $options[
                    $durationKey
                ][
                    'modify'
                ]
            );
}


/* =========================================================
   ACTIVE COMPLIMENTARY GRANT
   ========================================================= */

function llama_active_complimentary_grant(
    PDO $db,
    int $userId,
    ?string $at = null
): ?array {

    if (
        $userId < 1
    ) {

        return null;

    }


    $at =
        $at
        ?:
        gmdate(
            'Y-m-d H:i:s'
        );


    $stmt =
        $db->prepare(
            '
            SELECT
                mg.*,

                grantor.username
                    AS granted_by_username,

                grantor.display_name
                    AS granted_by_display_name

            FROM membership_grants mg

            LEFT JOIN users grantor
                ON grantor.id =
                    mg.granted_by

            WHERE mg.user_id = ?

              AND mg.grant_type = ?

              AND mg.revoked_at IS NULL

              AND mg.starts_at <= ?

              AND mg.ends_at > ?

            ORDER BY
                mg.ends_at DESC,
                mg.id DESC

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId,
        LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY,
        $at,
        $at,
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
   USER HAS COMPLIMENTARY GRANT
   ========================================================= */

function llama_user_has_complimentary_grant(
    PDO $db,
    int $userId,
    ?string $at = null
): bool {

    return
        llama_active_complimentary_grant(
            $db,
            $userId,
            $at
        )
        !== null;
}


/* =========================================================
   CREATE COMPLIMENTARY GRANT
   ========================================================= */

function llama_create_complimentary_grant(
    PDO $db,
    int $userId,
    int $grantedBy,
    string $durationKey,
    ?string $reason = null,
    ?string $notes = null
): int {

    if (
        $userId < 1
        ||
        $grantedBy < 1
    ) {

        throw new InvalidArgumentException(
            'A valid member and granting Owner are required.'
        );

    }


    $startsAt =
        new DateTimeImmutable(
            'now',
            new DateTimeZone(
                'UTC'
            )
        );


    $endsAt =
        llama_membership_grant_end(
            $durationKey,
            $startsAt
        );


    $stmt =
        $db->prepare(
            '
            INSERT INTO membership_grants
            (
                user_id,
                grant_type,
                starts_at,
                ends_at,
                reason,
                notes,
                granted_by
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


    $stmt->execute([
        $userId,
        LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY,
        $startsAt
            ->format(
                'Y-m-d H:i:s'
            ),
        $endsAt
            ->format(
                'Y-m-d H:i:s'
            ),
        $reason,
        $notes,
        $grantedBy,
    ]);


    $grantId =
        (int)
        $db->lastInsertId();


    llama_membership_audit(
        $db,
        $grantedBy,
        'complimentary_grant_created',
        'membership_grant',
        $grantId,
        [
            'user_id' =>
                $userId,

            'duration' =>
                $durationKey,

            'starts_at' =>
                $startsAt
                    ->format(
                        DATE_ATOM
                    ),

            'ends_at' =>
                $endsAt
                    ->format(
                        DATE_ATOM
                    ),

            'reason' =>
                $reason,
        ]
    );


    return
        $grantId;
}


/* =========================================================
   REVOKE COMPLIMENTARY GRANT
   ========================================================= */

function llama_revoke_complimentary_grant(
    PDO $db,
    int $grantId,
    int $revokedBy,
    ?string $reason = null
): void {

    if (
        $grantId < 1
        ||
        $revokedBy < 1
    ) {

        throw new InvalidArgumentException(
            'A valid grant and revoking Owner are required.'
        );

    }


    $stmt =
        $db->prepare(
            '
            UPDATE membership_grants

            SET
                revoked_at =
                    CURRENT_TIMESTAMP,

                revoked_by = ?,

                revoke_reason = ?

            WHERE id = ?

              AND grant_type = ?

              AND revoked_at IS NULL
            '
        );


    $stmt->execute([
        $revokedBy,
        $reason,
        $grantId,
        LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY,
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The complimentary membership grant could not be revoked.'
        );

    }


    llama_membership_audit(
        $db,
        $revokedBy,
        'complimentary_grant_revoked',
        'membership_grant',
        $grantId,
        [
            'reason' =>
                $reason,
        ]
    );
}


/* =========================================================
   MEMBERSHIP AUDIT LOG
   ========================================================= */

function llama_membership_audit(
    PDO $db,
    ?int $actorUserId,
    string $action,
    string $subjectType,
    ?int $subjectId = null,
    ?array $details = null
): void {

    $action =
        trim(
            $action
        );


    $subjectType =
        trim(
            $subjectType
        );


    if (
        $action === ''
        ||
        $subjectType === ''
    ) {

        throw new InvalidArgumentException(
            'Membership audit action and subject type are required.'
        );

    }


    $detailsJson =
        $details !== null
            ? json_encode(
                $details,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            )
            : null;


    if (
        $details !== null
        &&
        $detailsJson === false
    ) {

        throw new RuntimeException(
            'Membership audit details could not be encoded.'
        );

    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO membership_audit_log
            (
                actor_user_id,
                action,
                subject_type,
                subject_id,
                details_json
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


    $stmt->execute([
        $actorUserId,
        $action,
        $subjectType,
        $subjectId,
        $detailsJson,
    ]);
}
