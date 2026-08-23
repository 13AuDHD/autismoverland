<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT MEMBERSHIP SERVICE
   VERSIONED PRICING

   Shared data layer for:

   - Monthly / Annual membership plans
   - Immutable historical membership price versions
   - Current Stripe Price references
   - Scheduled site-wide promotions
   - Promotion pinning to a specific price version
   - Complimentary access grants
   - Membership administration audit history

   COMPATIBILITY

   membership_plans.base_price_cents and stripe_price_id remain
   compatibility shadows of the current price version so older
   callers continue to work during migration.

   Monetary values are integer cents.
   Database schedule timestamps are UTC.
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


const LLAMA_PROMOTION_DURATION_STRIPE_MANAGED =
    'stripe_managed';

const LLAMA_PROMOTION_DURATION_ONCE =
    'once';

const LLAMA_PROMOTION_DURATION_REPEATING =
    'repeating';

const LLAMA_PROMOTION_DURATION_FOREVER =
    'forever';


const LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY =
    'complimentary';


/* =========================================================
   SCHEMA HELPERS
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


function llama_membership_column_exists(
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


function llama_membership_add_column_if_missing(
    PDO $db,
    string $table,
    string $column,
    string $definition
): void {

    if (
        llama_membership_column_exists(
            $db,
            $table,
            $column
        )
    ) {

        return;
    }


    $safeTable =
        str_replace(
            '`',
            '``',
            $table
        );


    $safeColumn =
        str_replace(
            '`',
            '``',
            $column
        );


    $db->exec(
        'ALTER TABLE `'
        .
        $safeTable
        .
        '` ADD COLUMN `'
        .
        $safeColumn
        .
        '` '
        .
        $definition
    );
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
       STABLE MEMBERSHIP PLANS
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
       IMMUTABLE PRICE VERSIONS
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_plan_prices
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            plan_id BIGINT UNSIGNED
                NOT NULL,

            amount_cents INT UNSIGNED
                NOT NULL,

            currency CHAR(3)
                NOT NULL DEFAULT \'usd\',

            stripe_price_id VARCHAR(255)
                NULL,

            is_current TINYINT(1)
                NOT NULL DEFAULT 0,

            effective_from DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            effective_to DATETIME
                NULL,

            created_by BIGINT UNSIGNED
                NULL,

            change_reason VARCHAR(255)
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_membership_plan_price_plan
                (plan_id, is_current, id),

            KEY idx_membership_plan_price_current
                (is_current, plan_id),

            UNIQUE KEY uq_membership_plan_price_stripe
                (stripe_price_id),

            CONSTRAINT fk_membership_plan_price_plan
                FOREIGN KEY (plan_id)
                REFERENCES membership_plans(id)
                ON DELETE CASCADE,

            CONSTRAINT fk_membership_plan_price_creator
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
       PROMOTIONS
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
       PROMOTION -> PLAN RULES
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

            plan_price_id BIGINT UNSIGNED
                NULL,

            discount_type VARCHAR(20)
                NOT NULL,

            discount_value INT UNSIGNED
                NOT NULL,

            stripe_coupon_id VARCHAR(255)
                NULL,

            discount_duration VARCHAR(30)
                NOT NULL DEFAULT \'stripe_managed\',

            duration_count INT UNSIGNED
                NULL,

            allow_manual_promotion_codes TINYINT(1)
                NOT NULL DEFAULT 0,

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

            KEY idx_membership_promotion_plan_price
                (plan_price_id),

            CONSTRAINT fk_membership_promotion_plan_promotion
                FOREIGN KEY (promotion_id)
                REFERENCES membership_promotions(id)
                ON DELETE CASCADE,

            CONSTRAINT fk_membership_promotion_plan_plan
                FOREIGN KEY (plan_id)
                REFERENCES membership_plans(id)
                ON DELETE CASCADE,

            CONSTRAINT fk_membership_promotion_plan_price
                FOREIGN KEY (plan_price_id)
                REFERENCES membership_plan_prices(id)
                ON DELETE SET NULL
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /*
     * Existing installations created before versioned pricing
     * already have membership_promotion_plans. Add the newer
     * columns in place without destroying existing rows.
     */

    llama_membership_add_column_if_missing(
        $db,
        'membership_promotion_plans',
        'plan_price_id',
        'BIGINT UNSIGNED NULL'
    );

    llama_membership_add_column_if_missing(
        $db,
        'membership_promotion_plans',
        'discount_duration',
        'VARCHAR(30) NOT NULL DEFAULT \'stripe_managed\''
    );

    llama_membership_add_column_if_missing(
        $db,
        'membership_promotion_plans',
        'duration_count',
        'INT UNSIGNED NULL'
    );

    llama_membership_add_column_if_missing(
        $db,
        'membership_promotion_plans',
        'allow_manual_promotion_codes',
        'TINYINT(1) NOT NULL DEFAULT 0'
    );


    /* =====================================================
       COMPLIMENTARY MEMBERSHIP GRANTS
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
       AUDIT HISTORY
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


    llama_membership_migrate_legacy_prices(
        $db
    );


    llama_membership_backfill_promotion_price_versions(
        $db
    );
}


/* =========================================================
   DEFAULT PLAN SEED
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
   LEGACY PRICE MIGRATION
   ========================================================= */

function llama_membership_migrate_legacy_prices(
    PDO $db
): void {

    $plans =
        $db
            ->query(
                '
                SELECT
                    id,
                    currency,
                    base_price_cents,
                    stripe_price_id

                FROM membership_plans

                ORDER BY id ASC
                '
            )
            ->fetchAll(
                PDO::FETCH_ASSOC
            );


    $hasCurrentStmt =
        $db->prepare(
            '
            SELECT id

            FROM membership_plan_prices

            WHERE plan_id = ?
              AND is_current = 1

            ORDER BY id DESC

            LIMIT 1
            '
        );


    $insertStmt =
        $db->prepare(
            '
            INSERT INTO membership_plan_prices
            (
                plan_id,
                amount_cents,
                currency,
                stripe_price_id,
                is_current,
                effective_from,
                change_reason
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                1,
                CURRENT_TIMESTAMP,
                ?
            )
            '
        );


    foreach (
        $plans as
        $plan
    ) {

        $hasCurrentStmt->execute([
            (int)
            $plan[
                'id'
            ]
        ]);


        if (
            $hasCurrentStmt->fetchColumn()
        ) {

            continue;
        }


        $stripePriceId =
            trim(
                (string) (
                    $plan[
                        'stripe_price_id'
                    ]
                    ?? ''
                )
            );


        $insertStmt->execute([
            (int)
            $plan[
                'id'
            ],
            (int)
            $plan[
                'base_price_cents'
            ],
            strtolower(
                trim(
                    (string)
                    $plan[
                        'currency'
                    ]
                )
            )
            ?: 'usd',
            $stripePriceId !== ''
                ? $stripePriceId
                : null,
            'Migrated from membership_plans',
        ]);
    }


    llama_membership_sync_legacy_price_shadows(
        $db
    );
}


function llama_membership_sync_legacy_price_shadows(
    PDO $db
): void {

    $db->exec(
        '
        UPDATE membership_plans p

        INNER JOIN membership_plan_prices pp
            ON pp.plan_id = p.id
           AND pp.is_current = 1

        SET
            p.base_price_cents =
                pp.amount_cents,

            p.currency =
                pp.currency,

            p.stripe_price_id =
                pp.stripe_price_id
        '
    );
}


/* =========================================================
   PRICE VERSION READ APIs
   ========================================================= */

function llama_membership_current_price_row(
    PDO $db,
    int $planId
): ?array {

    if (
        $planId < 1
    ) {

        return null;
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                plan_id,
                amount_cents,
                currency,
                stripe_price_id,
                is_current,
                effective_from,
                effective_to,
                created_by,
                change_reason,
                created_at

            FROM membership_plan_prices

            WHERE plan_id = ?
              AND is_current = 1

            ORDER BY id DESC

            LIMIT 1
            '
        );


    $stmt->execute([
        $planId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
        ?: null;
}


function llama_membership_price_history(
    PDO $db,
    int $planId
): array {

    if (
        $planId < 1
    ) {

        return [];
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                pp.id,
                pp.plan_id,
                pp.amount_cents,
                pp.currency,
                pp.stripe_price_id,
                pp.is_current,
                pp.effective_from,
                pp.effective_to,
                pp.created_by,
                pp.change_reason,
                pp.created_at,

                u.username
                    AS created_by_username,

                u.display_name
                    AS created_by_display_name

            FROM membership_plan_prices pp

            LEFT JOIN users u
                ON u.id =
                    pp.created_by

            WHERE pp.plan_id = ?

            ORDER BY
                pp.is_current DESC,
                pp.effective_from DESC,
                pp.id DESC
            '
        );


    $stmt->execute([
        $planId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


function llama_membership_price_by_stripe_price_id(
    PDO $db,
    string $stripePriceId
): ?array {

    $stripePriceId =
        trim(
            $stripePriceId
        );


    if (
        $stripePriceId === ''
    ) {

        return null;
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                pp.id,
                pp.plan_id,
                pp.amount_cents,
                pp.currency,
                pp.stripe_price_id,
                pp.is_current,
                pp.effective_from,
                pp.effective_to,
                pp.created_by,
                pp.change_reason,
                pp.created_at,

                p.interval_slug,
                p.name AS plan_name,
                p.stripe_product_id,
                p.is_active AS plan_is_active

            FROM membership_plan_prices pp

            INNER JOIN membership_plans p
                ON p.id =
                    pp.plan_id

            WHERE pp.stripe_price_id = ?

            ORDER BY
                pp.is_current DESC,
                pp.id DESC

            LIMIT 1
            '
        );


    $stmt->execute([
        $stripePriceId
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
   CREATE NEW PRICE VERSION
   ========================================================= */

function llama_insert_membership_price_version(
    PDO $db,
    int $planId,
    int $amountCents,
    string $currency,
    ?string $stripePriceId,
    ?int $createdBy = null,
    ?string $changeReason = null
): int {

    if (
        $planId < 1
        ||
        $amountCents < 1
    ) {

        throw new InvalidArgumentException(
            'A valid membership plan and positive price are required.'
        );
    }


    $currency =
        strtolower(
            trim(
                $currency
            )
        );


    if (
        !preg_match(
            '/^[a-z]{3}$/',
            $currency
        )
    ) {

        throw new InvalidArgumentException(
            'Membership currency must be a three-letter code.'
        );
    }


    $stripePriceId =
        trim(
            (string)
            $stripePriceId
        );


    if (
        $stripePriceId === ''
    ) {

        $stripePriceId =
            null;
    }


    if (
        $stripePriceId !== null
    ) {

        $existing =
            llama_membership_price_by_stripe_price_id(
                $db,
                $stripePriceId
            );


        if (
            $existing
            &&
            (
                (int)
                $existing[
                    'plan_id'
                ]
                !==
                $planId
                ||
                (int)
                $existing[
                    'amount_cents'
                ]
                !==
                $amountCents
            )
        ) {

            throw new RuntimeException(
                'That Stripe Price ID is already assigned to a different membership price.'
            );
        }
    }


    $now =
        gmdate(
            'Y-m-d H:i:s'
        );


    $close =
        $db->prepare(
            '
            UPDATE membership_plan_prices

            SET
                is_current = 0,
                effective_to = ?

            WHERE plan_id = ?
              AND is_current = 1
            '
        );


    $close->execute([
        $now,
        $planId,
    ]);


    $insert =
        $db->prepare(
            '
            INSERT INTO membership_plan_prices
            (
                plan_id,
                amount_cents,
                currency,
                stripe_price_id,
                is_current,
                effective_from,
                effective_to,
                created_by,
                change_reason
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                1,
                ?,
                NULL,
                ?,
                ?
            )
            '
        );


    $insert->execute([
        $planId,
        $amountCents,
        $currency,
        $stripePriceId,
        $now,
        $createdBy,
        $changeReason,
    ]);


    $priceId =
        (int)
        $db->lastInsertId();


    $shadow =
        $db->prepare(
            '
            UPDATE membership_plans

            SET
                base_price_cents = ?,
                currency = ?,
                stripe_price_id = ?

            WHERE id = ?
            '
        );


    $shadow->execute([
        $amountCents,
        $currency,
        $stripePriceId,
        $planId,
    ]);


    return
        $priceId;
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
            p.id,
            p.interval_slug,
            p.name,
            p.description,

            COALESCE(
                cp.currency,
                p.currency
            ) AS currency,

            COALESCE(
                cp.amount_cents,
                p.base_price_cents
            ) AS base_price_cents,

            p.stripe_product_id,

            COALESCE(
                cp.stripe_price_id,
                p.stripe_price_id
            ) AS stripe_price_id,

            cp.id
                AS current_price_id,

            cp.effective_from
                AS current_price_effective_from,

            cp.change_reason
                AS current_price_change_reason,

            p.is_active,
            p.sort_order,
            p.created_at,
            p.updated_at

        FROM membership_plans p

        LEFT JOIN membership_plan_prices cp
            ON cp.plan_id = p.id
           AND cp.is_current = 1
        ';


    if (
        $activeOnly
    ) {

        $sql .=
            '
            WHERE p.is_active = 1
            ';
    }


    $sql .=
        '
        ORDER BY
            p.sort_order ASC,
            p.id ASC
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
            p.id,
            p.interval_slug,
            p.name,
            p.description,

            COALESCE(
                cp.currency,
                p.currency
            ) AS currency,

            COALESCE(
                cp.amount_cents,
                p.base_price_cents
            ) AS base_price_cents,

            p.stripe_product_id,

            COALESCE(
                cp.stripe_price_id,
                p.stripe_price_id
            ) AS stripe_price_id,

            cp.id
                AS current_price_id,

            cp.effective_from
                AS current_price_effective_from,

            cp.change_reason
                AS current_price_change_reason,

            p.is_active,
            p.sort_order,
            p.created_at,
            p.updated_at

        FROM membership_plans p

        LEFT JOIN membership_plan_prices cp
            ON cp.plan_id = p.id
           AND cp.is_current = 1

        WHERE p.interval_slug = ?
        ';


    if (
        $activeOnly
    ) {

        $sql .=
            '
            AND p.is_active = 1
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
   PROMOTION LEGACY BACKFILL
   ========================================================= */

function llama_membership_backfill_promotion_price_versions(
    PDO $db
): void {

    if (
        !llama_membership_column_exists(
            $db,
            'membership_promotion_plans',
            'plan_price_id'
        )
    ) {

        return;
    }


    $db->exec(
        '
        UPDATE membership_promotion_plans mpp

        INNER JOIN membership_plan_prices pp
            ON pp.plan_id =
                mpp.plan_id
           AND pp.is_current = 1

        SET
            mpp.plan_price_id =
                pp.id

        WHERE mpp.plan_price_id IS NULL
        '
    );
}


/* =========================================================
   PROMOTION OVERLAP CHECK
   ========================================================= */

function llama_membership_promotion_conflicts(
    PDO $db,
    int $planId,
    string $startsAt,
    string $endsAt,
    ?int $excludePromotionId = null
): array {

    if (
        $planId < 1
    ) {

        return [];
    }


    $sql =
        '
        SELECT
            mp.id,
            mp.name,
            mp.starts_at,
            mp.ends_at

        FROM membership_promotions mp

        INNER JOIN membership_promotion_plans mpp
            ON mpp.promotion_id =
                mp.id

        WHERE mpp.plan_id = ?

          AND mp.is_enabled = 1

          AND mp.starts_at < ?

          AND mp.ends_at > ?
        ';


    $params = [
        $planId,
        $endsAt,
        $startsAt,
    ];


    if (
        $excludePromotionId !== null
        &&
        $excludePromotionId > 0
    ) {

        $sql .=
            '
            AND mp.id <> ?
            ';


        $params[] =
            $excludePromotionId;
    }


    $sql .=
        '
        ORDER BY
            mp.starts_at ASC,
            mp.id ASC
        ';


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
   ACTIVE PROMOTION FOR PLAN
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

                mpp.plan_price_id,

                mpp.discount_type,

                mpp.discount_value,

                mpp.stripe_coupon_id,

                mpp.discount_duration,

                mpp.duration_count,

                mpp.allow_manual_promotion_codes

            FROM membership_promotions mp

            INNER JOIN membership_promotion_plans mpp
                ON mpp.promotion_id =
                    mp.id

            INNER JOIN membership_plan_prices cp
                ON cp.plan_id =
                    mpp.plan_id
               AND cp.is_current = 1

            WHERE mpp.plan_id = ?

              AND mpp.plan_price_id =
                    cp.id

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

        'discount_duration' =>
            $promotion[
                'discount_duration'
            ]
            ?? null,

        'duration_count' =>
            isset(
                $promotion[
                    'duration_count'
                ]
            )
                ? (int)
                  $promotion[
                      'duration_count'
                  ]
                : null,

        'allow_manual_promotion_codes' =>
            !empty(
                $promotion[
                    'allow_manual_promotion_codes'
                ]
            ),
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
