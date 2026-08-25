<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/memberships.php';
require_once dirname(__DIR__) . '/app/timezone.php';
require_once dirname(__DIR__) . '/app/role-display.php';

require_role('owner');
start_llama_session();

$db = db();
$user = current_user();

if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

$ownerId = (int) $user['id'];

$primaryRoleLabel =
    llama_primary_role_label(
        $ownerId
    );

$primaryRoleIcon =
    llama_primary_role_icon(
        $ownerId
    );

llama_ensure_membership_storage($db);

$ownerTimezoneName = llama_user_timezone($user);

try {
    $ownerTimezone = new DateTimeZone($ownerTimezoneName);
} catch (Throwable) {
    $ownerTimezone = new DateTimeZone('UTC');
    $ownerTimezoneName = 'UTC';
}

if (empty($_SESSION['owner_memberships_csrf'])) {
    $_SESSION['owner_memberships_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['owner_memberships_csrf'];


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


function membership_owner_csrf(string $expected): void
{
    $submitted =
        $_POST['csrf_token']
        ?? '';

    if (
        !is_string($submitted)
        ||
        $submitted === ''
        ||
        !hash_equals($expected, $submitted)
    ) {
        throw new RuntimeException(
            'Your session could not be verified. Reload the page and try again.'
        );
    }
}


function membership_owner_money_to_cents(string $value): int
{
    $value =
        str_replace(
            ['$', ',', ' '],
            '',
            trim($value)
        );

    if (
        $value === ''
        ||
        !is_numeric($value)
    ) {
        throw new InvalidArgumentException(
            'Enter a valid dollar amount.'
        );
    }

    $amount = (float) $value;

    if ($amount < 0) {
        throw new InvalidArgumentException(
            'Amount cannot be negative.'
        );
    }

    return (int) round($amount * 100);
}


function membership_owner_cents_input(int $cents): string
{
    return number_format(
        $cents / 100,
        2,
        '.',
        ''
    );
}


function membership_owner_optional(
    mixed $value,
    int $maxLength = 255
): ?string {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException(
            'One of the submitted values is too long.'
        );
    }

    return $value;
}


function membership_owner_local_to_utc(
    string $value,
    DateTimeZone $timezone
): string {
    $value = trim($value);

    $date =
        DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $value,
            $timezone
        );

    if (!$date) {
        throw new InvalidArgumentException(
            'Enter a valid date and time.'
        );
    }

    return
        $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
}


function membership_owner_format_datetime(
    ?string $value,
    DateTimeZone $timezone
): string {
    if (!$value) {
        return 'Not set';
    }

    try {
        return
            (new DateTimeImmutable(
                $value,
                new DateTimeZone('UTC')
            ))
                ->setTimezone($timezone)
                ->format('M j, Y g:i A T');
    } catch (Throwable) {
        return (string) $value;
    }
}


function membership_owner_promotion_status_label(
    string $status
): string {
    return match ($status) {
        'live' => 'Live Now',
        'scheduled' => 'Scheduled',
        'ended' => 'Ended',
        'disabled' => 'Disabled',
        default => 'Unknown',
    };
}


/* =========================================================
   POST ACTIONS
   ========================================================= */

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        membership_owner_csrf($csrfToken);

        $action =
            trim(
                (string) (
                    $_POST['action']
                    ?? ''
                )
            );

        /* -------------------------------------------------
           UPDATE PLAN / CREATE PRICE VERSION
           ------------------------------------------------- */
        if ($action === 'update_plan') {
            $planId =
                (int) (
                    $_POST['plan_id']
                    ?? 0
                );

            if ($planId < 1) {
                throw new InvalidArgumentException(
                    'Invalid membership plan.'
                );
            }

            $before =
                null;

            foreach (
                llama_membership_plans(
                    $db,
                    false
                )
                as
                $candidate
            ) {
                if (
                    (int)
                    $candidate['id']
                    ===
                    $planId
                ) {
                    $before =
                        $candidate;
                    break;
                }
            }

            if (!$before) {
                throw new RuntimeException(
                    'Membership plan not found.'
                );
            }

            $priceCents =
                membership_owner_money_to_cents(
                    (string) (
                        $_POST['base_price']
                        ?? ''
                    )
                );

            if ($priceCents < 1) {
                throw new InvalidArgumentException(
                    'Membership price must be greater than zero.'
                );
            }

            $stripeProductId =
                membership_owner_optional(
                    $_POST['stripe_product_id']
                    ?? null
                );

            $stripePriceId =
                membership_owner_optional(
                    $_POST['stripe_price_id']
                    ?? null
                );

            $changeReason =
                membership_owner_optional(
                    $_POST['price_change_reason']
                    ?? null,
                    255
                );

            $isActive =
                isset($_POST['is_active'])
                    ? 1
                    : 0;

            $oldAmount =
                (int)
                $before['base_price_cents'];

            $oldStripePrice =
                trim(
                    (string) (
                        $before['stripe_price_id']
                        ?? ''
                    )
                );

            $newStripePrice =
                trim(
                    (string) (
                        $stripePriceId
                        ?? ''
                    )
                );

            $priceChanged =
                $priceCents !==
                    $oldAmount
                ||
                $newStripePrice !==
                    $oldStripePrice;

            /*
             * Permanent price changes must have a matching
             * Stripe Price ID. Stripe Price amounts are
             * immutable, so a new amount must never reuse the
             * old Stripe Price.
             */
            if (
                $priceCents !==
                    $oldAmount
                &&
                (
                    $stripePriceId === null
                    ||
                    $newStripePrice ===
                        $oldStripePrice
                )
            ) {
                throw new InvalidArgumentException(
                    'A permanent price change requires a new Stripe Price ID created for that exact amount and billing interval.'
                );
            }

            $db->beginTransaction();

            /*
             * Stable plan settings.
             */
            $planUpdate =
                $db->prepare(
                    '
                    UPDATE membership_plans

                    SET
                        stripe_product_id = ?,
                        is_active = ?

                    WHERE id = ?
                    '
                );

            $planUpdate->execute([
                $stripeProductId,
                $isActive,
                $planId,
            ]);

            $priceVersionId =
                isset(
                    $before['current_price_id']
                )
                    ? (int)
                      $before['current_price_id']
                    : 0;

            if ($priceChanged) {
                $priceVersionId =
                    llama_insert_membership_price_version(
                        $db,
                        $planId,
                        $priceCents,
                        (string)
                        $before['currency'],
                        $stripePriceId,
                        $ownerId,
                        $changeReason
                        ?:
                        'Owner membership price update'
                    );
            }

            llama_membership_audit(
                $db,
                $ownerId,
                'membership_plan_updated',
                'membership_plan',
                $planId,
                [
                    'before' => [
                        'price_version_id' =>
                            isset(
                                $before['current_price_id']
                            )
                                ? (int)
                                  $before['current_price_id']
                                : null,

                        'base_price_cents' =>
                            $oldAmount,

                        'stripe_product_id' =>
                            $before['stripe_product_id']
                            ?? null,

                        'stripe_price_id' =>
                            $before['stripe_price_id']
                            ?? null,

                        'is_active' =>
                            (int)
                            $before['is_active'],
                    ],

                    'after' => [
                        'price_version_id' =>
                            $priceVersionId,

                        'base_price_cents' =>
                            $priceCents,

                        'stripe_product_id' =>
                            $stripeProductId,

                        'stripe_price_id' =>
                            $stripePriceId,

                        'is_active' =>
                            $isActive,
                    ],

                    'price_changed' =>
                        $priceChanged,

                    'change_reason' =>
                        $changeReason,
                ]
            );

            $db->commit();

            $success =
                $priceChanged
                    ? $before['name']
                      . ' membership saved with a new price version.'
                    : $before['name']
                      . ' membership settings updated.';
        }

        /* -------------------------------------------------
           CREATE PROMOTION
           ------------------------------------------------- */
        elseif ($action === 'create_promotion') {
            $name =
                membership_owner_optional(
                    $_POST['promotion_name']
                    ?? null,
                    150
                );

            if (!$name) {
                throw new InvalidArgumentException(
                    'Promotion name is required.'
                );
            }

            $publicLabel =
                membership_owner_optional(
                    $_POST['public_label']
                    ?? null,
                    150
                );

            $publicDescription =
                membership_owner_optional(
                    $_POST['public_description']
                    ?? null,
                    5000
                );

            $startsAt =
                membership_owner_local_to_utc(
                    (string) (
                        $_POST['starts_at']
                        ?? ''
                    ),
                    $ownerTimezone
                );

            $endsAt =
                membership_owner_local_to_utc(
                    (string) (
                        $_POST['ends_at']
                        ?? ''
                    ),
                    $ownerTimezone
                );

            if (
                strtotime($endsAt)
                <=
                strtotime($startsAt)
            ) {
                throw new InvalidArgumentException(
                    'Promotion end must be after its start.'
                );
            }

            $plansForPromotion =
                llama_membership_plans(
                    $db,
                    false
                );

            $rules = [];

            foreach ($plansForPromotion as $plan) {
                $interval =
                    (string)
                    $plan['interval_slug'];

                if (
                    !isset(
                        $_POST[
                            $interval
                            . '_promotion_enabled'
                        ]
                    )
                ) {
                    continue;
                }

                $currentPriceId =
                    (int) (
                        $plan['current_price_id']
                        ?? 0
                    );

                if ($currentPriceId < 1) {
                    throw new RuntimeException(
                        $plan['name']
                        . ' does not have a current price version.'
                    );
                }

                $conflicts =
                    llama_membership_promotion_conflicts(
                        $db,
                        (int)
                        $plan['id'],
                        $startsAt,
                        $endsAt
                    );

                if ($conflicts) {
                    throw new RuntimeException(
                        $plan['name']
                        . ' already has an enabled promotion that overlaps this time window.'
                    );
                }

                $discountType =
                    trim(
                        (string) (
                            $_POST[
                                $interval
                                . '_discount_type'
                            ]
                            ?? ''
                        )
                    );

                if (
                    !in_array(
                        $discountType,
                        [
                            LLAMA_PROMOTION_DISCOUNT_PERCENT,
                            LLAMA_PROMOTION_DISCOUNT_AMOUNT,
                        ],
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Invalid promotion discount type.'
                    );
                }

                $rawValue =
                    trim(
                        (string) (
                            $_POST[
                                $interval
                                . '_discount_value'
                            ]
                            ?? ''
                        )
                    );

                if (
                    $discountType
                    ===
                    LLAMA_PROMOTION_DISCOUNT_PERCENT
                ) {
                    if (
                        $rawValue === ''
                        ||
                        !ctype_digit($rawValue)
                    ) {
                        throw new InvalidArgumentException(
                            'Percentage discounts must be whole numbers.'
                        );
                    }

                    $discountValue =
                        (int)
                        $rawValue;

                    if (
                        $discountValue < 1
                        ||
                        $discountValue > 100
                    ) {
                        throw new InvalidArgumentException(
                            'Percentage discounts must be between 1 and 100.'
                        );
                    }
                } else {
                    $discountValue =
                        membership_owner_money_to_cents(
                            $rawValue
                        );

                    if (
                        $discountValue < 1
                        ||
                        $discountValue
                        >=
                        (int)
                        $plan['base_price_cents']
                    ) {
                        throw new InvalidArgumentException(
                            'Fixed discounts must be greater than zero and less than the regular price.'
                        );
                    }
                }

                $stripeCouponId =
                    membership_owner_optional(
                        $_POST[
                            $interval
                            . '_stripe_coupon_id'
                        ]
                        ?? null
                    );

                if (!$stripeCouponId) {
                    throw new InvalidArgumentException(
                        'A Stripe Coupon ID is required for every plan included in an automatic sale.'
                    );
                }

                $rules[] = [
                    'plan_id' =>
                        (int)
                        $plan['id'],

                    'plan_price_id' =>
                        $currentPriceId,

                    'interval' =>
                        $interval,

                    'base_price_cents' =>
                        (int)
                        $plan['base_price_cents'],

                    'discount_type' =>
                        $discountType,

                    'discount_value' =>
                        $discountValue,

                    'stripe_coupon_id' =>
                        $stripeCouponId,

                    /*
                     * The coupon configuration in Stripe is
                     * authoritative for how long the discount
                     * applies. We record that explicitly.
                     */
                    'discount_duration' =>
                        LLAMA_PROMOTION_DURATION_STRIPE_MANAGED,

                    'duration_count' =>
                        null,

                    'allow_manual_promotion_codes' =>
                        0,
                ];
            }

            if (!$rules) {
                throw new InvalidArgumentException(
                    'Select at least one membership plan for this promotion.'
                );
            }

            $db->beginTransaction();

            $promotionStmt =
                $db->prepare(
                    '
                    INSERT INTO membership_promotions
                    (
                        name,
                        public_label,
                        public_description,
                        starts_at,
                        ends_at,
                        is_enabled,
                        created_by
                    )

                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        1,
                        ?
                    )
                    '
                );

            $promotionStmt->execute([
                $name,
                $publicLabel,
                $publicDescription,
                $startsAt,
                $endsAt,
                $ownerId,
            ]);

            $promotionId =
                (int)
                $db->lastInsertId();

            $ruleStmt =
                $db->prepare(
                    '
                    INSERT INTO membership_promotion_plans
                    (
                        promotion_id,
                        plan_id,
                        plan_price_id,
                        discount_type,
                        discount_value,
                        stripe_coupon_id,
                        discount_duration,
                        duration_count,
                        allow_manual_promotion_codes
                    )

                    VALUES
                    (
                        ?,
                        ?,
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

            foreach ($rules as $rule) {
                $ruleStmt->execute([
                    $promotionId,
                    $rule['plan_id'],
                    $rule['plan_price_id'],
                    $rule['discount_type'],
                    $rule['discount_value'],
                    $rule['stripe_coupon_id'],
                    $rule['discount_duration'],
                    $rule['duration_count'],
                    $rule['allow_manual_promotion_codes'],
                ]);
            }

            llama_membership_audit(
                $db,
                $ownerId,
                'membership_promotion_created',
                'membership_promotion',
                $promotionId,
                [
                    'name' =>
                        $name,

                    'starts_at' =>
                        $startsAt,

                    'ends_at' =>
                        $endsAt,

                    'rules' =>
                        $rules,
                ]
            );

            $db->commit();

            $success =
                'Promotion created and pinned to the current membership price version.';
        }

        /* -------------------------------------------------
           ENABLE / DISABLE PROMOTION
           ------------------------------------------------- */
        elseif ($action === 'toggle_promotion') {
            $promotionId =
                (int) (
                    $_POST['promotion_id']
                    ?? 0
                );

            if ($promotionId < 1) {
                throw new InvalidArgumentException(
                    'Invalid promotion.'
                );
            }

            $stmt =
                $db->prepare(
                    '
                    SELECT
                        id,
                        name,
                        starts_at,
                        ends_at,
                        is_enabled

                    FROM membership_promotions

                    WHERE id = ?

                    LIMIT 1
                    '
                );

            $stmt->execute([
                $promotionId
            ]);

            $promotion =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$promotion) {
                throw new RuntimeException(
                    'Promotion not found.'
                );
            }

            $newState =
                !empty(
                    $promotion['is_enabled']
                )
                    ? 0
                    : 1;

            /*
             * Re-enabling must still obey current price
             * pinning and overlap rules.
             */
            if ($newState === 1) {
                $ruleStmt =
                    $db->prepare(
                        '
                        SELECT
                            mpr.plan_id,
                            mpr.plan_price_id,
                            mp.name AS plan_name

                        FROM membership_promotion_plans mpr

                        INNER JOIN membership_plans mp
                            ON mp.id = mpr.plan_id

                        WHERE mpr.promotion_id = ?
                        '
                    );

                $ruleStmt->execute([
                    $promotionId
                ]);

                $toggleRules =
                    $ruleStmt->fetchAll(
                        PDO::FETCH_ASSOC
                    );

                foreach ($toggleRules as $rule) {
                    $currentPrice =
                        llama_membership_current_price_row(
                            $db,
                            (int)
                            $rule['plan_id']
                        );

                    if (
                        !$currentPrice
                        ||
                        (int)
                        $rule['plan_price_id']
                        !==
                        (int)
                        $currentPrice['id']
                    ) {
                        throw new RuntimeException(
                            $rule['plan_name']
                            . ' now has a different regular price. Create a new promotion for the current price instead of re-enabling this one.'
                        );
                    }

                    $conflicts =
                        llama_membership_promotion_conflicts(
                            $db,
                            (int)
                            $rule['plan_id'],
                            (string)
                            $promotion['starts_at'],
                            (string)
                            $promotion['ends_at'],
                            $promotionId
                        );

                    if ($conflicts) {
                        throw new RuntimeException(
                            $rule['plan_name']
                            . ' has another enabled promotion overlapping this time window.'
                        );
                    }
                }
            }

            $db->beginTransaction();

            $stmt =
                $db->prepare(
                    '
                    UPDATE membership_promotions
                    SET is_enabled = ?
                    WHERE id = ?
                    '
                );

            $stmt->execute([
                $newState,
                $promotionId,
            ]);

            llama_membership_audit(
                $db,
                $ownerId,
                $newState
                    ? 'membership_promotion_enabled'
                    : 'membership_promotion_disabled',
                'membership_promotion',
                $promotionId,
                [
                    'name' =>
                        $promotion['name'],
                ]
            );

            $db->commit();

            $success =
                $newState
                    ? 'Promotion enabled.'
                    : 'Promotion disabled.';
        }

        /* -------------------------------------------------
           COMPLIMENTARY GRANT
           ------------------------------------------------- */
        elseif ($action === 'create_complimentary') {
            $lookup =
                strtolower(
                    trim(
                        (string) (
                            $_POST['member_lookup']
                            ?? ''
                        )
                    )
                );

            if ($lookup === '') {
                throw new InvalidArgumentException(
                    'Enter a username or email address.'
                );
            }

            $stmt =
                $db->prepare(
                    '
                    SELECT
                        id,
                        username,
                        email,
                        display_name

                    FROM users

                    WHERE LOWER(username) = ?
                       OR LOWER(email) = ?

                    LIMIT 1
                    '
                );

            $stmt->execute([
                $lookup,
                $lookup,
            ]);

            $target =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$target) {
                throw new RuntimeException(
                    'No account matches that username or email address.'
                );
            }

            $duration =
                trim(
                    (string) (
                        $_POST['grant_duration']
                        ?? ''
                    )
                );

            $reason =
                membership_owner_optional(
                    $_POST['grant_reason']
                    ?? null
                );

            $notes =
                membership_owner_optional(
                    $_POST['grant_notes']
                    ?? null,
                    5000
                );

            llama_create_complimentary_grant(
                $db,
                (int)
                $target['id'],
                $ownerId,
                $duration,
                $reason,
                $notes
            );

            $success =
                'Complimentary access granted to '
                .
                (
                    $target['display_name']
                    ?:
                    $target['username']
                    ?:
                    $target['email']
                )
                .
                '.';
        }

        /* -------------------------------------------------
           REVOKE COMPLIMENTARY GRANT
           ------------------------------------------------- */
        elseif ($action === 'revoke_complimentary') {
            $grantId =
                (int) (
                    $_POST['grant_id']
                    ?? 0
                );

            $reason =
                membership_owner_optional(
                    $_POST['revoke_reason']
                    ?? null
                );

            llama_revoke_complimentary_grant(
                $db,
                $grantId,
                $ownerId,
                $reason
            );

            $success =
                'Complimentary access revoked.';
        }

        else {
            throw new InvalidArgumentException(
                'Unknown membership action.'
            );
        }

    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $error =
            $exception->getMessage();
    }
}


/* =========================================================
   LOAD DATA
   ========================================================= */

$plans =
    llama_membership_plans(
        $db,
        false
    );

$offers =
    llama_membership_offers(
        $db
    );


$priceHistoryByPlan = [];

foreach ($plans as $plan) {
    $priceHistoryByPlan[
        (int)
        $plan['id']
    ] =
        llama_membership_price_history(
            $db,
            (int)
            $plan['id']
        );
}


$promotions =
    $db
        ->query(
            '
            SELECT
                mp.id,
                mp.name,
                mp.public_label,
                mp.public_description,
                mp.starts_at,
                mp.ends_at,
                mp.is_enabled,
                mp.created_at,

                u.username
                    AS created_by_username,

                u.display_name
                    AS created_by_display_name

            FROM membership_promotions mp

            LEFT JOIN users u
                ON u.id = mp.created_by

            ORDER BY
                mp.starts_at DESC,
                mp.id DESC
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


$promotionRulesById = [];

$ruleRows =
    $db
        ->query(
            '
            SELECT
                mpr.promotion_id,
                mpr.plan_id,
                mpr.plan_price_id,
                mpr.discount_type,
                mpr.discount_value,
                mpr.stripe_coupon_id,
                mpr.discount_duration,
                mpr.duration_count,
                mpr.allow_manual_promotion_codes,

                p.interval_slug,
                p.name AS plan_name,

                price.amount_cents
                    AS base_price_cents,

                price.currency,

                price.stripe_price_id
                    AS pinned_stripe_price_id,

                price.is_current
                    AS price_is_current

            FROM membership_promotion_plans mpr

            INNER JOIN membership_plans p
                ON p.id = mpr.plan_id

            LEFT JOIN membership_plan_prices price
                ON price.id =
                    mpr.plan_price_id

            ORDER BY
                mpr.promotion_id DESC,
                p.sort_order ASC,
                p.id ASC
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


foreach ($ruleRows as $rule) {
    $promotionRulesById[
        (int)
        $rule['promotion_id']
    ][] =
        $rule;
}


$grants =
    $db
        ->query(
            '
            SELECT
                mg.id,
                mg.user_id,
                mg.starts_at,
                mg.ends_at,
                mg.reason,
                mg.notes,
                mg.revoked_at,
                mg.revoke_reason,
                mg.created_at,

                member.username,
                member.display_name,
                member.email

            FROM membership_grants mg

            INNER JOIN users member
                ON member.id =
                    mg.user_id

            WHERE mg.grant_type =
                \'complimentary\'

            ORDER BY
                CASE
                    WHEN mg.revoked_at IS NULL
                     AND mg.ends_at > UTC_TIMESTAMP()
                    THEN 0
                    ELSE 1
                END,

                mg.ends_at DESC,
                mg.id DESC

            LIMIT 250
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


/* =========================================================
   COUNTS
   ========================================================= */

$paidMonthlyCount =
    (int)
    $db
        ->query(
            "
            SELECT COUNT(*)
            FROM users
            WHERE membership_status IN
            ('active','trialing','past_due')
              AND membership_interval = 'monthly'
            "
        )
        ->fetchColumn();

$paidAnnualCount =
    (int)
    $db
        ->query(
            "
            SELECT COUNT(*)
            FROM users
            WHERE membership_status IN
            ('active','trialing','past_due')
              AND membership_interval = 'annual'
            "
        )
        ->fetchColumn();

$complimentaryCount =
    (int)
    $db
        ->query(
            "
            SELECT COUNT(DISTINCT user_id)
            FROM membership_grants
            WHERE grant_type = 'complimentary'
              AND revoked_at IS NULL
              AND starts_at <= UTC_TIMESTAMP()
              AND ends_at > UTC_TIMESTAMP()
            "
        )
        ->fetchColumn();

$pastDueCount =
    (int)
    $db
        ->query(
            "
            SELECT COUNT(*)
            FROM users
            WHERE membership_status = 'past_due'
            "
        )
        ->fetchColumn();

$cancelingCount =
    (int)
    $db
        ->query(
            "
            SELECT COUNT(*)
            FROM users
            WHERE stripe_cancel_at_period_end = 1
              AND membership_status IN
              ('active','trialing','past_due')
            "
        )
        ->fetchColumn();


/* =========================================================
   DEFAULT PROMOTION DATES
   ========================================================= */

$nowLocal =
    new DateTimeImmutable(
        'now',
        $ownerTimezone
    );

$nextHour =
    $nowLocal
        ->modify('+1 hour');

$defaultStart =
    $nextHour
        ->setTime(
            (int)
            $nextHour->format('H'),
            0
        );

$defaultEnd =
    $defaultStart
        ->modify('+7 days');

$durationOptions =
    llama_membership_grant_duration_options();

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
    Memberships | Llama Scout Basecamp
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
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


</head>


<body class="admin-page">

<?php
require_once
    dirname(__DIR__)
    . '/app/header.php';
?>


<main class="admin-main">

  <div class="membership-back-row">

    <a
      href="/"
      class="back-link"
    >
      <i
        class="fa-solid fa-arrow-left"
        aria-hidden="true"
      ></i>

      Back to Basecamp
    </a>

  </div>


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
          Memberships
        </h1>


        <p>
          Manage membership pricing, Stripe plan references,
          scheduled promotions, complimentary access, and
          membership status from one place.
        </p>

      </div>

    </div>

  </section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <?php if ($success !== ''): ?>

    <div
      class="
        owner-notice
        owner-notice--success
      "
    >
      <?= e($success) ?>
    </div>

  <?php endif; ?>


  <?php if ($error !== ''): ?>

    <div
      class="
        owner-notice
        owner-notice--error
      "
    >
      <?= e($error) ?>
    </div>

  <?php endif; ?>


  <!-- =====================================================
       OVERVIEW
       ===================================================== -->

  <section class="membership-owner-section">

    <div class="membership-owner-section-header">
      <h2>
        Membership Overview
      </h2>

      <p>
        Current subscription and access counts.
      </p>
    </div>


    <div class="membership-owner-stats">

      <article class="membership-owner-stat">
        <span>Monthly Paid</span>
        <strong><?= $paidMonthlyCount ?></strong>
      </article>

      <article class="membership-owner-stat">
        <span>Annual Paid</span>
        <strong><?= $paidAnnualCount ?></strong>
      </article>

      <article class="membership-owner-stat">
        <span>Complimentary</span>
        <strong><?= $complimentaryCount ?></strong>
      </article>

      <article class="membership-owner-stat">
        <span>Past Due</span>
        <strong><?= $pastDueCount ?></strong>
      </article>

      <article class="membership-owner-stat">
        <span>Canceling</span>
        <strong><?= $cancelingCount ?></strong>
      </article>

    </div>

  </section>


  <!-- =====================================================
       PLANS
       ===================================================== -->

  <section class="membership-owner-section">

    <div class="membership-owner-section-header">

      <h2>
        Plans & Pricing
      </h2>

      <p>
        This is the shared source for public pricing,
        account pricing, checkout, and future pricing displays.
      </p>

    </div>


    <div
      class="plan-tabs"
      role="tablist"
      aria-label="Membership plans"
    >

      <?php foreach ($plans as $index => $plan): ?>

        <button
          type="button"
          class="plan-tab"
          role="tab"
          id="plan-tab-<?= e(
              $plan['interval_slug']
          ) ?>"
          aria-controls="plan-panel-<?= e(
              $plan['interval_slug']
          ) ?>"
          aria-selected="<?= $index === 0
              ? 'true'
              : 'false'
          ?>"
          data-plan-tab="<?= e(
              $plan['interval_slug']
          ) ?>"
        >
          <?= e($plan['name']) ?>
        </button>

      <?php endforeach; ?>

    </div>


    <?php foreach ($plans as $index => $plan): ?>

      <?php
      $interval =
          (string)
          $plan['interval_slug'];

      $offer =
          $offers[$interval]
          ?? null;
      ?>

      <article
        class="
          membership-owner-card
          plan-panel
        "
        id="plan-panel-<?= e($interval) ?>"
        role="tabpanel"
        aria-labelledby="plan-tab-<?= e($interval) ?>"
        data-plan-panel="<?= e($interval) ?>"
        <?= $index === 0
            ? ''
            : 'hidden'
        ?>
      >

        <h3>
          <?= e($plan['name']) ?>
        </h3>

        <p>
          <?= e($plan['description']) ?>
        </p>


        <?php if ($offer): ?>

          <div class="owner-price-preview">

            <?php if (!empty($offer['on_sale'])): ?>

              <del>
                <?= e(
                    llama_membership_format_money(
                        (int)
                        $offer['base_price_cents'],
                        (string)
                        $plan['currency']
                    )
                ) ?>
              </del>

              <strong>
                <?= e(
                    llama_membership_format_money(
                        (int)
                        $offer['effective_price_cents'],
                        (string)
                        $plan['currency']
                    )
                ) ?>
              </strong>

              <div class="owner-small">
                Current sale price
              </div>

            <?php else: ?>

              <strong>
                <?= e(
                    llama_membership_format_money(
                        (int)
                        $plan['base_price_cents'],
                        (string)
                        $plan['currency']
                    )
                ) ?>
              </strong>

              <div class="owner-small">
                Regular price
              </div>

            <?php endif; ?>

          </div>

        <?php endif; ?>


        <div class="stripe-help-box">

          <p>
            <strong>Stripe Product ID:</strong>
            identifies the membership product in Stripe.
            It usually begins with <code>prod_</code>.
          </p>

          <p>
            <strong>Stripe Price ID:</strong>
            identifies the exact recurring price and billing
            interval Stripe charges. It usually begins with
            <code>price_</code>. Checkout ultimately uses
            this ID.
          </p>

          <p>
            If you permanently change the regular price,
            create the matching new recurring Price in Stripe
            and place its new Price ID here. Existing
            subscribers can remain on their existing Stripe
            Price unless you intentionally migrate them.
          </p>

        </div>


        <form method="post">

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
          >

          <input
            type="hidden"
            name="action"
            value="update_plan"
          >

          <input
            type="hidden"
            name="plan_id"
            value="<?= (int) $plan['id'] ?>"
          >


          <div class="owner-form-grid">

            <div class="owner-field">

              <label>
                Regular Price
              </label>

              <input
                type="text"
                inputmode="decimal"
                name="base_price"
                value="<?= e(
                    membership_owner_cents_input(
                        (int)
                        $plan['base_price_cents']
                    )
                ) ?>"
                required
              >

            </div>


            <div class="owner-field">

              <label>
                Billing Interval
              </label>

              <input
                type="text"
                value="<?= e(
                    ucfirst($interval)
                ) ?>"
                disabled
              >

            </div>


            <div
              class="
                owner-field
                owner-field--full
              "
            >

              <label>
                Stripe Product ID
              </label>

              <input
                type="text"
                name="stripe_product_id"
                value="<?= e(
                    $plan['stripe_product_id']
                    ?? ''
                ) ?>"
                placeholder="prod_..."
              >

            </div>


            <div
              class="
                owner-field
                owner-field--full
              "
            >

              <label>
                Stripe Price ID
              </label>

              <input
                type="text"
                name="stripe_price_id"
                value="<?= e(
                    $plan['stripe_price_id']
                    ?? ''
                ) ?>"
                placeholder="price_..."
              >

            </div>


            <div
              class="
                owner-field
                owner-field--full
              "
            >

              <label>
                Price Change Note
              </label>

              <input
                type="text"
                name="price_change_reason"
                placeholder="Optional, e.g. 2027 regular price adjustment"
              >

              <div class="owner-help">
                If the amount or Stripe Price ID changes,
                Llama Scout creates a new permanent price
                version and keeps the previous version for
                existing subscriptions and webhook history.
              </div>

            </div>

          </div>


          <label class="owner-check">

            <input
              type="checkbox"
              name="is_active"
              value="1"
              <?= !empty($plan['is_active'])
                  ? 'checked'
                  : ''
              ?>
            >

            Accept new
            <?= e($plan['name']) ?>
            memberships

          </label>


          <div class="owner-actions">

            <button
              type="submit"
              class="owner-button"
            >
              <i
                class="fa-solid fa-floppy-disk"
                aria-hidden="true"
              ></i>
              Save Plan
            </button>

          </div>

        </form>


        <?php
        $history =
            $priceHistoryByPlan[
                (int)
                $plan['id']
            ]
            ?? [];
        ?>


        <?php if ($history): ?>

          <div class="price-history">

            <h4>
              Price History
            </h4>

            <div class="price-history-list">

              <?php foreach ($history as $priceVersion): ?>

                <div class="price-history-row">

                  <div>

                    <strong>
                      <?= e(
                          llama_membership_format_money(
                              (int)
                              $priceVersion['amount_cents'],
                              (string)
                              $priceVersion['currency']
                          )
                      ) ?>
                    </strong>

                    <?php if (
                        !empty(
                            $priceVersion['is_current']
                        )
                    ): ?>

                      <span class="price-history-current">
                        Current
                      </span>

                    <?php endif; ?>

                  </div>


                  <div>

                    <?php if (
                        !empty(
                            $priceVersion['stripe_price_id']
                        )
                    ): ?>

                      <code>
                        <?= e(
                            $priceVersion['stripe_price_id']
                        ) ?>
                      </code>

                    <?php else: ?>

                      <span class="owner-small">
                        No Stripe Price ID
                      </span>

                    <?php endif; ?>

                  </div>


                  <div class="owner-small">

                    <?= e(
                        membership_owner_format_datetime(
                            $priceVersion['effective_from']
                            ?? null,
                            $ownerTimezone
                        )
                    ) ?>

                  </div>

                </div>

              <?php endforeach; ?>

            </div>

          </div>

        <?php endif; ?>


      </article>

    <?php endforeach; ?>

  </section>


  <!-- =====================================================
       SCHEDULE SALE
       ===================================================== -->

  <section class="membership-owner-section">

    <div class="membership-owner-section-header">

      <h2>
        Schedule a Sale
      </h2>

      <p>
        Sales turn on and off automatically. Each sale is
        pinned to the exact regular-price version in effect
        when you schedule it.
      </p>

    </div>


    <article class="membership-owner-card">

      <form method="post">

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e($csrfToken) ?>"
        >

        <input
          type="hidden"
          name="action"
          value="create_promotion"
        >


        <div class="owner-form-grid">

          <div class="owner-field">

            <label>
              Internal Promotion Name
            </label>

            <input
              type="text"
              name="promotion_name"
              placeholder="Holiday Sale 2026"
              required
            >

          </div>


          <div class="owner-field">

            <label>
              Public Sale Label
            </label>

            <input
              type="text"
              name="public_label"
              placeholder="Holiday Sale"
            >

          </div>

        </div>


        <div
          class="
            owner-form-grid
            sale-date-grid
          "
          style="margin-top:12px;"
        >

          <div class="owner-field">

            <label>
              Starts
            </label>

            <input
              type="datetime-local"
              name="starts_at"
              value="<?= e(
                  $defaultStart
                      ->format('Y-m-d\TH:i')
              ) ?>"
              required
            >

          </div>


          <div class="owner-field">

            <label>
              Ends
            </label>

            <input
              type="datetime-local"
              name="ends_at"
              value="<?= e(
                  $defaultEnd
                      ->format('Y-m-d\TH:i')
              ) ?>"
              required
            >

          </div>

        </div>


        <div
          class="owner-field"
          style="margin-top:12px;"
        >

          <label>
            Public Description
          </label>

          <textarea
            name="public_description"
            placeholder="Save on your first billing period during our holiday sale."
          ></textarea>

        </div>


        <p class="owner-help">
          Times are entered in
          <strong><?= e($ownerTimezoneName) ?></strong>
          and stored internally in UTC.
        </p>


        <div class="promotion-plan-grid">

          <?php foreach ($plans as $plan): ?>

            <?php
            $interval =
                (string)
                $plan['interval_slug'];
            ?>

            <div class="promotion-card">

              <label class="owner-check">

                <input
                  type="checkbox"
                  name="<?= e(
                      $interval
                  ) ?>_promotion_enabled"
                  value="1"
                  checked
                >

                Include
                <?= e($plan['name']) ?>

              </label>


              <div
                class="owner-form-grid"
                style="margin-top:12px;"
              >

                <div class="owner-field">

                  <label>
                    Discount Type
                  </label>

                  <select
                    name="<?= e(
                        $interval
                    ) ?>_discount_type"
                  >

                    <option value="percent">
                      Percentage
                    </option>

                    <option value="amount">
                      Fixed Dollar Amount
                    </option>

                  </select>

                </div>


                <div class="owner-field">

                  <label>
                    Discount Value
                  </label>

                  <input
                    type="text"
                    name="<?= e(
                        $interval
                    ) ?>_discount_value"
                    value="20"
                  >

                </div>


                <div
                  class="
                    owner-field
                    owner-field--full
                  "
                >

                  <label>
                    Stripe Coupon ID
                  </label>

                  <input
                    type="text"
                    name="<?= e(
                        $interval
                    ) ?>_stripe_coupon_id"
                    placeholder="coupon_..."
                  >

                  <div class="owner-help">
                    Required. This Stripe Coupon is
                    automatically attached to checkout while
                    the sale is active. Its duration settings
                    in Stripe control whether the discount
                    applies once, repeats, or continues.
                  </div>

                </div>

              </div>


              <p class="owner-help">
                Percentage example: <strong>20</strong>.
                Fixed amount example:
                <strong>10.00</strong>.
              </p>

            </div>

          <?php endforeach; ?>

        </div>


        <div class="owner-actions">

          <button
            type="submit"
            class="owner-button"
          >
            <i
              class="fa-solid fa-calendar-plus"
              aria-hidden="true"
            ></i>
            Schedule Promotion
          </button>

        </div>

      </form>

    </article>

  </section>


  <!-- =====================================================
       PROMOTIONS
       ===================================================== -->

  <section class="membership-owner-section">

    <div class="membership-owner-section-header">

      <h2>
        Promotions
      </h2>

      <p>
        Scheduled, active, ended, and disabled site-wide sales.
      </p>

    </div>


    <?php if (!$promotions): ?>

      <div class="owner-empty">
        No promotions have been created yet.
      </div>

    <?php endif; ?>


    <?php foreach ($promotions as $promotion): ?>

      <?php
      $promotionStatus =
          llama_membership_promotion_status(
              $promotion
          );

      $rules =
          $promotionRulesById[
              (int) $promotion['id']
          ]
          ?? [];
      ?>

      <article class="promotion-card">

        <div class="promotion-card-header">

          <div>

            <h3>
              <?= e($promotion['name']) ?>
            </h3>

            <?php if (
                !empty(
                    $promotion['public_label']
                )
            ): ?>

              <div class="owner-small">
                Public:
                <?= e(
                    $promotion['public_label']
                ) ?>
              </div>

            <?php endif; ?>

          </div>


          <span
            class="
              owner-pill
              <?= $promotionStatus === 'live'
                  ? 'owner-pill--live'
                  : ''
              ?>
            "
          >
            <?= e(
                membership_owner_promotion_status_label(
                    $promotionStatus
                )
            ) ?>
          </span>

        </div>


        <div class="owner-meta">

          <div>
            <span>Starts</span>
            <strong>
              <?= e(
                  membership_owner_format_datetime(
                      $promotion['starts_at'],
                      $ownerTimezone
                  )
              ) ?>
            </strong>
          </div>

          <div>
            <span>Ends</span>
            <strong>
              <?= e(
                  membership_owner_format_datetime(
                      $promotion['ends_at'],
                      $ownerTimezone
                  )
              ) ?>
            </strong>
          </div>

        </div>


        <?php if (
            !empty(
                $promotion['public_description']
            )
        ): ?>

          <p>
            <?= e(
                $promotion['public_description']
            ) ?>
          </p>

        <?php endif; ?>


        <div class="owner-rule-list">

          <?php foreach ($rules as $rule): ?>

            <?php
            $ruleBasePrice =
                isset(
                    $rule['base_price_cents']
                )
                &&
                $rule['base_price_cents']
                !==
                null
                    ? (int)
                      $rule['base_price_cents']
                    : 0;

            $discounted =
                llama_membership_discounted_price_cents(
                    $ruleBasePrice,
                    (string)
                    $rule['discount_type'],
                    (int)
                    $rule['discount_value']
                );
            ?>

            <div class="owner-rule">

              <span>
                <?= e($rule['plan_name']) ?>
              </span>

              <strong>

                <?php if (
                    $rule['discount_type']
                    ===
                    LLAMA_PROMOTION_DISCOUNT_PERCENT
                ): ?>

                  <?= (int)
                      $rule['discount_value']
                  ?>% off

                <?php else: ?>

                  <?= e(
                      llama_membership_format_money(
                          (int)
                          $rule['discount_value'],
                          (string)
                          $rule['currency']
                      )
                  ) ?>
                  off

                <?php endif; ?>

                â

                <?= e(
                    llama_membership_format_money(
                        $discounted,
                        (string)
                        $rule['currency']
                    )
                ) ?>

              </strong>

              <div
                class="owner-small"
                style="margin-top:4px;"
              >
                Pinned price version #<?= (int)
                    ($rule['plan_price_id'] ?? 0)
                ?>

                <?php if (
                    !empty(
                        $rule['pinned_stripe_price_id']
                    )
                ): ?>
                  Â·
                  <code>
                    <?= e(
                        $rule['pinned_stripe_price_id']
                    ) ?>
                  </code>
                <?php endif; ?>

                <?php if (
                    empty(
                        $rule['price_is_current']
                    )
                ): ?>
                  Â· historical regular price
                <?php endif; ?>
              </div>

            </div>

          <?php endforeach; ?>

        </div>


        <form method="post">

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
          >

          <input
            type="hidden"
            name="action"
            value="toggle_promotion"
          >

          <input
            type="hidden"
            name="promotion_id"
            value="<?= (int)
                $promotion['id']
            ?>"
          >


          <div class="owner-actions">

            <button
              type="submit"
              class="
                owner-button
                owner-button--secondary
              "
            >
              <?= !empty($promotion['is_enabled'])
                  ? 'Disable'
                  : 'Enable'
              ?>
            </button>

          </div>

        </form>

      </article>

    <?php endforeach; ?>

  </section>


  <!-- =====================================================
       COMPLIMENTARY MEMBERSHIPS
       ===================================================== -->

  <section class="membership-owner-section">

    <div class="membership-owner-section-header">

      <h2>
        Complimentary Memberships
      </h2>

      <p>
        Grant temporary full membership access to beta testers,
        creators, press, influencers, partners, or other people
        helping Llama Scout.
      </p>

    </div>


    <article class="membership-owner-card">

      <h3>
        Grant Complimentary Access
      </h3>

      <p>
        The recipient must already have a Llama Scout account.
      </p>


      <form method="post">

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e($csrfToken) ?>"
        >

        <input
          type="hidden"
          name="action"
          value="create_complimentary"
        >


        <div class="owner-form-grid">

          <div class="owner-field">

            <label>
              Username or Email
            </label>

            <input
              type="text"
              name="member_lookup"
              placeholder="username or name@example.com"
              required
            >

          </div>


          <div class="owner-field">

            <label>
              Access Duration
            </label>

            <select
              name="grant_duration"
              required
            >

              <?php foreach (
                  $durationOptions as
                  $key =>
                  $option
              ): ?>

                <option
                  value="<?= e($key) ?>"
                  <?= $key === '1m'
                      ? 'selected'
                      : ''
                  ?>
                >
                  <?= e($option['label']) ?>
                </option>

              <?php endforeach; ?>

            </select>

          </div>


          <div class="owner-field">

            <label>
              Reason
            </label>

            <input
              type="text"
              name="grant_reason"
              placeholder="Beta tester, influencer, press..."
            >

          </div>


          <div
            class="
              owner-field
              owner-field--full
            "
          >

            <label>
              Private Notes
            </label>

            <textarea
              name="grant_notes"
              placeholder="Optional internal notes about this complimentary membership."
            ></textarea>

          </div>

        </div>


        <div class="owner-actions">

          <button
            type="submit"
            class="owner-button"
          >
            <i
              class="fa-solid fa-gift"
              aria-hidden="true"
            ></i>
            Grant Access
          </button>

        </div>

      </form>

    </article>


    <?php if (!$grants): ?>

      <div
        class="owner-empty"
        style="margin-top:16px;"
      >
        No complimentary memberships have been issued yet.
      </div>

    <?php endif; ?>


    <?php foreach ($grants as $grant): ?>

      <?php
      $grantStart =
          strtotime(
              (string)
              $grant['starts_at']
          );

      $grantEnd =
          strtotime(
              (string)
              $grant['ends_at']
          );

      $grantActive =
          empty($grant['revoked_at'])
          &&
          $grantStart !== false
          &&
          $grantEnd !== false
          &&
          $grantStart <= time()
          &&
          $grantEnd > time();
      ?>

      <article class="grant-card">

        <div class="grant-card-header">

          <div>

            <h3>
              <?= e(
                  $grant['display_name']
                  ?:
                  $grant['username']
                  ?:
                  $grant['email']
              ) ?>
            </h3>

            <div class="owner-small">
              <?= e($grant['email']) ?>
            </div>

          </div>


          <span
            class="
              owner-pill
              <?= $grantActive
                  ? 'owner-pill--live'
                  : ''
              ?>
            "
          >

            <?php
            if (!empty($grant['revoked_at'])) {
                echo 'Revoked';
            } elseif (
                $grantEnd !== false
                &&
                $grantEnd <= time()
            ) {
                echo 'Expired';
            } else {
                echo 'Active';
            }
            ?>

          </span>

        </div>


        <div class="owner-meta">

          <div>
            <span>Started</span>
            <strong>
              <?= e(
                  membership_owner_format_datetime(
                      $grant['starts_at'],
                      $ownerTimezone
                  )
              ) ?>
            </strong>
          </div>

          <div>
            <span>Access Through</span>
            <strong>
              <?= e(
                  membership_owner_format_datetime(
                      $grant['ends_at'],
                      $ownerTimezone
                  )
              ) ?>
            </strong>
          </div>

        </div>


        <?php if (!empty($grant['reason'])): ?>

          <p>
            <strong>Reason:</strong>
            <?= e($grant['reason']) ?>
          </p>

        <?php endif; ?>


        <?php if (!empty($grant['notes'])): ?>

          <p class="owner-small">
            <?= nl2br(
                e($grant['notes'])
            ) ?>
          </p>

        <?php endif; ?>


        <?php if ($grantActive): ?>

          <form method="post">

            <input
              type="hidden"
              name="csrf_token"
              value="<?= e($csrfToken) ?>"
            >

            <input
              type="hidden"
              name="action"
              value="revoke_complimentary"
            >

            <input
              type="hidden"
              name="grant_id"
              value="<?= (int) $grant['id'] ?>"
            >


            <div
              class="owner-field"
              style="margin-top:14px;"
            >

              <label>
                Revocation Reason
              </label>

              <input
                type="text"
                name="revoke_reason"
                placeholder="Optional"
              >

            </div>


            <div class="owner-actions">

              <button
                type="submit"
                class="
                  owner-button
                  owner-button--danger
                "
              >
                Revoke Access
              </button>

            </div>

          </form>

        <?php endif; ?>

      </article>

    <?php endforeach; ?>

  </section>


</main>


<?php
require_once
    dirname(__DIR__)
    . '/app/footer.php';
?>


<script
  src="https://llamascout.com/js/header.js"
></script>


<script>

(() => {

  const tabs =
    Array.from(
      document.querySelectorAll(
        "[data-plan-tab]"
      )
    );

  const panels =
    Array.from(
      document.querySelectorAll(
        "[data-plan-panel]"
      )
    );


  function activatePlan(
    interval
  ) {

    tabs.forEach(
      (tab) => {

        const active =
          tab.dataset.planTab
          ===
          interval;

        tab.setAttribute(
          "aria-selected",
          active
            ? "true"
            : "false"
        );

      }
    );


    panels.forEach(
      (panel) => {

        panel.hidden =
          panel.dataset.planPanel
          !==
          interval;

      }
    );


    try {

      sessionStorage.setItem(
        "llama_owner_membership_plan_tab",
        interval
      );

    } catch (error) {

      /* Storage is optional. */

    }

  }


  tabs.forEach(
    (tab) => {

      tab.addEventListener(
        "click",
        () => {

          activatePlan(
            tab.dataset.planTab
          );

        }
      );

    }
  );


  try {

    const saved =
      sessionStorage.getItem(
        "llama_owner_membership_plan_tab"
      );


    if (
      saved
      &&
      tabs.some(
        (tab) =>
          tab.dataset.planTab
          ===
          saved
      )
    ) {

      activatePlan(
        saved
      );

    }

  } catch (error) {

    /* Storage is optional. */

  }

})();

</script>


</body>

</html>
