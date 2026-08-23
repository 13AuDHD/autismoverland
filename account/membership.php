<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/memberships.php';


require_login();
start_llama_session();


$db =
    db();


$user =
    current_user();


if (
    !$user
) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );

}


$userId =
    (int)
    $user[
        'id'
    ];


/* =========================================================
   MEMBERSHIP STORAGE / OFFERS
   ========================================================= */

llama_ensure_membership_storage(
    $db
);


$offers =
    llama_membership_offers(
        $db
    );


$monthlyOffer =
    $offers[
        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY
    ]
    ?? null;


$annualOffer =
    $offers[
        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
    ]
    ?? null;


/* =========================================================
   ACCOUNT + STRIPE MEMBERSHIP
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            email,
            username,
            display_name,
            timezone,

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


$membership =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$membership
) {

    http_response_code(
        404
    );

    exit(
        'Account not found.'
    );

}


/* =========================================================
   ROLES
   ========================================================= */

$roles =
    user_roles(
        $userId
    );


$isOwner =
    in_array(
        'owner',
        $roles,
        true
    );


$isAdmin =
    in_array(
        'admin',
        $roles,
        true
    );


$isMasterScout =
    in_array(
        'master-scout',
        $roles,
        true
    )
    ||
    in_array(
        'master_scout',
        $roles,
        true
    );


$isScout =
    in_array(
        'scout',
        $roles,
        true
    );


$isMember =
    in_array(
        'member',
        $roles,
        true
    );


/* =========================================================
   SCOUT PROFILE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            status,
            approved_at,
            scout_started_at,
            active_through,
            inactive_at,
            removed_at,
            removal_reason

        FROM scout_profiles

        WHERE user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$scoutProfile =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: null;


$hasScoutProfile =
    $scoutProfile !== null;


$scoutStatus =
    $hasScoutProfile
        ? strtolower(
            trim(
                (string)
                $scoutProfile[
                    'status'
                ]
            )
        )
        : '';


$hasActiveScoutAccess =
    (
        $isScout
        ||
        $isMasterScout
    )
    &&
    $scoutStatus ===
        'active';


/* =========================================================
   ACTIVE SCOUT EXTENSION
   ========================================================= */

$activeScoutExtension =
    null;


if (
    $hasActiveScoutAccess
) {

    try {

        $extensionStmt =
            $db->prepare(
                '
                SELECT
                    id,
                    scout_profile_id,
                    user_id,
                    granted_by,
                    started_at,
                    ends_at,
                    status,
                    accepted_reports,
                    resolved_at

                FROM scout_extensions

                WHERE scout_profile_id = ?
                  AND user_id = ?
                  AND status = \'active\'

                ORDER BY
                    id DESC

                LIMIT 1
                '
            );


        $extensionStmt->execute([
            (int)
            $scoutProfile[
                'id'
            ],
            $userId
        ]);


        $extension =
            $extensionStmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            $extension
        ) {

            $activeScoutExtension =
                $extension;

        }

    } catch (
        Throwable $exception
    ) {

        error_log(
            'Llama Scout membership extension lookup error for user #'
            .
            $userId
            .
            ': '
            .
            $exception
                ->getMessage()
        );

    }

}


$isScoutExtension =
    is_array(
        $activeScoutExtension
    );


/* =========================================================
   COMPLIMENTARY ACCESS

   New complimentary access is stored independently from the
   Stripe membership fields.

   Legacy membership_status=complimentary remains recognized
   temporarily so an older account does not unexpectedly lose
   access before migration.
   ========================================================= */

$activeComplimentaryGrant =
    llama_active_complimentary_grant(
        $db,
        $userId
    );


$hasComplimentaryGrant =
    $activeComplimentaryGrant !==
        null;


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


function format_membership_date(
    ?string $date,
    array $membership
): string {

    if (
        !$date
    ) {

        return 'Not set';

    }


    return llama_format_datetime(
        $date,
        llama_user_timezone(
            $membership
        ),
        'M j, Y'
    );

}


function membership_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'active' =>
            'Active',

        'trialing' =>
            'Trial',

        'past_due' =>
            'Payment issue',

        'canceled' =>
            'Canceled',

        'complimentary' =>
            'Legacy Complimentary',

        default =>
            'Free',

    };

}


function membership_interval_label(
    ?string $interval
): string {

    return match (
        (string)
        $interval
    ) {

        'monthly' =>
            'Monthly',

        'annual' =>
            'Annual',

        default =>
            'None',

    };

}


function scout_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'active' =>
            'Active Scout',

        'inactive' =>
            'Inactive Scout',

        'removed' =>
            'Removed',

        'pending_approval' =>
            'Awaiting Approval',

        'training' =>
            'Training',

        'application_submitted' =>
            'About You Complete',

        'application_started' =>
            'Onboarding',

        'invited' =>
            'Invited',

        'declined' =>
            'Declined',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-',
                    ],
                    ' ',
                    $status
                )
            ),

    };

}


function account_membership_offer_price(
    ?array $offer
): string {

    if (
        !$offer
    ) {

        return 'Unavailable';

    }


    return llama_membership_format_money(
        (int)
        $offer[
            'effective_price_cents'
        ],
        (string)
        $offer[
            'plan'
        ][
            'currency'
        ]
    );

}


function account_membership_regular_price(
    ?array $offer
): string {

    if (
        !$offer
    ) {

        return '';

    }


    return llama_membership_format_money(
        (int)
        $offer[
            'base_price_cents'
        ],
        (string)
        $offer[
            'plan'
        ][
            'currency'
        ]
    );

}


function account_membership_sale_label(
    ?array $offer
): ?string {

    if (
        !$offer
        ||
        empty(
            $offer[
                'on_sale'
            ]
        )
    ) {

        return null;

    }


    $label =
        trim(
            (string) (
                $offer[
                    'promotion'
                ][
                    'public_label'
                ]
                ?? ''
            )
        );


    return
        $label !== ''
            ? $label
            : 'Sale';

}


/* =========================================================
   PRIMARY ROLE
   ========================================================= */

if (
    $isOwner
) {

    $primaryRole =
        'Owner';

} elseif (
    $isAdmin
) {

    $primaryRole =
        'Admin';

} elseif (
    $isMasterScout
    &&
    $hasActiveScoutAccess
) {

    $primaryRole =
        'Master Scout';

} elseif (
    $isScout
    &&
    $hasActiveScoutAccess
) {

    $primaryRole =
        'Scout';

} elseif (
    $isMember
) {

    $primaryRole =
        'Member';

} else {

    $primaryRole =
        'User';

}


/* =========================================================
   MEMBERSHIP STATE
   ========================================================= */

$status =
    strtolower(
        trim(
            (string) (
                $membership[
                    'membership_status'
                ]
                ??
                'none'
            )
        )
    );


$isStripeMembership =
    in_array(
        $status,
        [
            'active',
            'trialing',
            'past_due',
        ],
        true
    );


$isLegacyComplimentary =
    $status ===
        'complimentary';


$hasPermanentAccess =
    $isOwner
    ||
    $isAdmin;


$hasMembershipAccess =
    $isStripeMembership
    ||
    $hasComplimentaryGrant
    ||
    $isLegacyComplimentary;


$hasFullAccess =
    $hasPermanentAccess
    ||
    $hasActiveScoutAccess
    ||
    $hasMembershipAccess;


/* =========================================================
   ACCESS SOURCES

   Access sources are additive. One does not overwrite another.
   ========================================================= */

$accessSources =
    [];


if (
    $isOwner
) {

    $accessSources[] =
        'Owner Role';

}


if (
    $isAdmin
    &&
    !$isOwner
) {

    $accessSources[] =
        'Admin Role';

}


if (
    $isScoutExtension
    &&
    $hasActiveScoutAccess
) {

    $accessSources[] =
        '30-Day Scout Extension';

} elseif (
    $isMasterScout
    &&
    $hasActiveScoutAccess
) {

    $accessSources[] =
        'Master Scout';

} elseif (
    $isScout
    &&
    $hasActiveScoutAccess
) {

    $accessSources[] =
        'Llama Scout';

}


if (
    $isStripeMembership
) {

    $accessSources[] =
        'Paid Membership';

}


if (
    $hasComplimentaryGrant
) {

    $accessSources[] =
        'Complimentary Membership';

} elseif (
    $isLegacyComplimentary
) {

    $accessSources[] =
        'Legacy Complimentary Access';

}


if (
    !$accessSources
) {

    $accessSources[] =
        'Free Account';

}


$accessSource =
    implode(
        ' + ',
        $accessSources
    );


/* =========================================================
   ACCESS THROUGH

   This is a simple summary. Individual access sources are
   shown in their own sections below.
   ========================================================= */

if (
    $hasPermanentAccess
) {

    $accessThrough =
        'No expiration';

} elseif (
    $isScoutExtension
    &&
    !empty(
        $activeScoutExtension[
            'ends_at'
        ]
    )
) {

    $accessThrough =
        format_membership_date(
            $activeScoutExtension[
                'ends_at'
            ],
            $membership
        );

} elseif (
    $hasActiveScoutAccess
    &&
    !empty(
        $scoutProfile[
            'active_through'
        ]
    )
) {

    $accessThrough =
        format_membership_date(
            $scoutProfile[
                'active_through'
            ],
            $membership
        );

} elseif (
    $hasComplimentaryGrant
    &&
    !empty(
        $activeComplimentaryGrant[
            'ends_at'
        ]
    )
) {

    $accessThrough =
        format_membership_date(
            $activeComplimentaryGrant[
                'ends_at'
            ],
            $membership
        );

} elseif (
    $isStripeMembership
    &&
    !empty(
        $membership[
            'membership_ends_at'
        ]
    )
) {

    $accessThrough =
        format_membership_date(
            $membership[
                'membership_ends_at'
            ],
            $membership
        );

} elseif (
    $hasFullAccess
) {

    $accessThrough =
        'Active';

} else {

    $accessThrough =
        'No full access';

}


/* =========================================================
   SCOUT DATES
   ========================================================= */

$scoutSince =
    $hasScoutProfile
    &&
    !empty(
        $scoutProfile[
            'scout_started_at'
        ]
    )
        ? format_membership_date(
            $scoutProfile[
                'scout_started_at'
            ],
            $membership
        )
        : 'Not yet';


$scoutActiveThrough =
    $hasScoutProfile
    &&
    !empty(
        $scoutProfile[
            'active_through'
        ]
    )
        ? format_membership_date(
            $scoutProfile[
                'active_through'
            ],
            $membership
        )
        : 'Not scheduled';


$extensionStarted =
    $isScoutExtension
    &&
    !empty(
        $activeScoutExtension[
            'started_at'
        ]
    )
        ? format_membership_date(
            $activeScoutExtension[
                'started_at'
            ],
            $membership
        )
        : 'Not applicable';


$extensionEnds =
    $isScoutExtension
    &&
    !empty(
        $activeScoutExtension[
            'ends_at'
        ]
    )
        ? format_membership_date(
            $activeScoutExtension[
                'ends_at'
            ],
            $membership
        )
        : 'Not applicable';


/* =========================================================
   COMPLIMENTARY DISPLAY
   ========================================================= */

$complimentaryStarted =
    $hasComplimentaryGrant
    ? format_membership_date(
        $activeComplimentaryGrant[
            'starts_at'
        ]
        ?? null,
        $membership
    )
    : 'Not applicable';


$complimentaryEnds =
    $hasComplimentaryGrant
    ? format_membership_date(
        $activeComplimentaryGrant[
            'ends_at'
        ]
        ?? null,
        $membership
    )
    : 'Not applicable';


/* =========================================================
   BILLING DISPLAY
   ========================================================= */

$billingPlan =
    membership_interval_label(
        $membership[
            'membership_interval'
        ]
        ??
        null
    );


$billingStatus =
    membership_status_label(
        $status
    );


$membershipStarted =
    !empty(
        $membership[
            'membership_started_at'
        ]
    )
        ? format_membership_date(
            $membership[
                'membership_started_at'
            ],
            $membership
        )
        : 'Not applicable';


$paidThrough =
    !empty(
        $membership[
            'membership_ends_at'
        ]
    )
        ? format_membership_date(
            $membership[
                'membership_ends_at'
            ],
            $membership
        )
        : (
            $isStripeMembership
                ? 'Not scheduled'
                : 'Not applicable'
        );


$cancelAtPeriodEnd =
    !empty(
        $membership[
            'stripe_cancel_at_period_end'
        ]
    );


if (
    $isStripeMembership
    &&
    $cancelAtPeriodEnd
) {

    $renewalStatus =
        'Will not renew';

} elseif (
    $isStripeMembership
) {

    $renewalStatus =
        'Renews automatically';

} elseif (
    $status ===
        'canceled'
) {

    $renewalStatus =
        'Canceled';

} else {

    $renewalStatus =
        'Not applicable';

}


/* =========================================================
   CHECKOUT CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'membership_checkout_csrf'
        ]
    )
) {

    $_SESSION[
        'membership_checkout_csrf'
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
        'membership_checkout_csrf'
    ];


/* =========================================================
   VISIBILITY
   ========================================================= */

$showMembershipPlans =
    !$hasPermanentAccess
    &&
    !$hasActiveScoutAccess
    &&
    !$hasMembershipAccess;


$showBillingSection =
    !empty(
        $membership[
            'stripe_customer_id'
        ]
    )
    ||
    $isStripeMembership
    ||
    $status ===
        'canceled'
    ||
    !empty(
        $membership[
            'membership_started_at'
        ]
    );


$showBillingPortal =
    !empty(
        $membership[
            'stripe_customer_id'
        ]
    )
    &&
    !empty(
        $membership[
            'stripe_subscription_id'
        ]
    );


/* =========================================================
   REQUESTED PLAN
   ========================================================= */

$requestedPlan =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'plan'
                ]
                ?? ''
            )
        )
    );


if (
    !in_array(
        $requestedPlan,
        [
            LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
            LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
        ],
        true
    )
) {

    $requestedPlan =
        '';

}


/* =========================================================
   CHECKOUT MESSAGE
   ========================================================= */

$checkoutMessage =
    '';


if (
    isset(
        $_GET[
            'checkout'
        ]
    )
) {

    if (
        $_GET[
            'checkout'
        ]
        ===
        'success'
    ) {

        $checkoutMessage =
            'Checkout completed. Stripe is confirming your membership. Your account will update automatically.';

    } elseif (
        $_GET[
            'checkout'
        ]
        ===
        'canceled'
    ) {

        $checkoutMessage =
            'Checkout was canceled. No changes were made to your membership.';

    }

}


$monthlyPrice =
    account_membership_offer_price(
        $monthlyOffer
    );


$annualPrice =
    account_membership_offer_price(
        $annualOffer
    );


$monthlyRegular =
    account_membership_regular_price(
        $monthlyOffer
    );


$annualRegular =
    account_membership_regular_price(
        $annualOffer
    );


$monthlySaleLabel =
    account_membership_sale_label(
        $monthlyOffer
    );


$annualSaleLabel =
    account_membership_sale_label(
        $annualOffer
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
    Membership | Llama Scout
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/account.css"
  >

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


  <style>

    .membership-page {
      width:
        min(
          100%,
          980px
        );
      margin: 0 auto;
      padding:
        34px
        18px
        80px;
    }

    .membership-card {
      margin-top: 22px;
      padding: 24px;
      border:
        1px solid
        rgba(23,40,34,.12);
      border-radius: 18px;
      background:
        rgba(255,255,255,.82);
    }

    .membership-card h2 {
      margin:
        0
        0
        8px;
    }

    .membership-card > p {
      margin:
        0
        0
        20px;
      line-height: 1.6;
      opacity: .76;
    }

    .membership-grid {
      display: grid;
      grid-template-columns:
        repeat(
          2,
          minmax(0,1fr)
        );
      gap: 12px;
    }

    .membership-item {
      padding: 15px;
      border-radius: 12px;
      background:
        rgba(23,40,34,.055);
    }

    .membership-item span {
      display: block;
      margin-bottom: 5px;
      font-size: .79rem;
      opacity: .64;
    }

    .membership-item strong {
      display: block;
      line-height: 1.45;
    }

    .membership-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      margin-bottom: 14px;
      padding:
        7px
        10px;
      border-radius: 999px;
      background: #172822;
      color: #fff;
      font-size: .8rem;
      font-weight: 750;
    }

    .membership-pill--gift {
      background: #2f819e;
    }

    .membership-note {
      display: flex;
      gap: 10px;
      margin-top: 16px;
      padding: 14px;
      border-radius: 12px;
      background:
        rgba(217,196,154,.16);
      line-height: 1.55;
    }

    .membership-note i {
      margin-top: 3px;
    }

    .membership-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 18px;
    }

    .membership-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 44px;
      padding:
        11px
        16px;
      border: 0;
      border-radius: 9px;
      background: #172822;
      color: #fff;
      text-decoration: none;
      font: inherit;
      font-weight: 750;
      cursor: pointer;
    }

    .membership-plans {
      display: grid;
      grid-template-columns:
        repeat(
          2,
          minmax(0,1fr)
        );
      gap: 16px;
      margin-top: 18px;
    }

    .membership-plan {
      position: relative;
      padding: 22px;
      border:
        1px solid
        rgba(23,40,34,.12);
      border-radius: 16px;
      background:
        rgba(255,255,255,.76);
    }

    .membership-plan.is-selected {
      border-color:
        rgba(47,129,158,.55);
      box-shadow:
        0 0 0 2px
        rgba(47,129,158,.08);
    }

    .membership-plan h3 {
      margin:
        0
        0
        8px;
    }

    .membership-plan-badge {
      display: inline-flex;
      margin-bottom: 10px;
      padding:
        5px
        8px;
      border-radius: 999px;
      background: #2f819e;
      color: #fff;
      font-size: .68rem;
      font-weight: 800;
      text-transform: uppercase;
    }

    .membership-price {
      margin-bottom: 12px;
      font-size: 2rem;
      font-weight: 800;
    }

    .membership-price span {
      font-size: .9rem;
      font-weight: 500;
      opacity: .65;
    }

    .membership-price del {
      margin-right: 6px;
      font-size: 1rem;
      font-weight: 500;
      opacity: .5;
    }

    .membership-plan ul {
      margin:
        14px
        0
        18px;
      padding-left: 20px;
      line-height: 1.65;
    }

    .membership-billing-disclosure {
      margin-top: 18px;
      padding:
        14px
        15px;
      border:
        1px solid
        rgba(23,40,34,.11);
      border-radius: 11px;
      background:
        rgba(47,129,158,.07);
      font-size: .8rem;
      line-height: 1.6;
      opacity: .86;
    }

    .membership-billing-disclosure strong {
      opacity: 1;
    }

    .membership-billing-disclosure a {
      font-weight: 750;
      text-decoration: underline;
    }

    @media (
      max-width: 680px
    ) {

      .membership-grid,
      .membership-plans {
        grid-template-columns: 1fr;
      }

    }

  </style>

</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="membership-page">


  <a
    href="/"
    class="back-link"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to My Account

  </a>


  <header class="page-header">

    <h1>
      Membership
    </h1>

    <p>
      Your current access, Scout status, complimentary access,
      and billing.
    </p>

  </header>


  <?php if (
      $checkoutMessage
  ): ?>

    <div class="notice">
      <?= e($checkoutMessage) ?>
    </div>

  <?php endif; ?>


  <!-- =====================================================
       CURRENT ACCESS
       ===================================================== -->

  <section class="membership-card">

    <h2>
      Current Access
    </h2>

    <p>
      What your account can access right now and why.
    </p>


    <div class="membership-grid">

      <div class="membership-item">

        <span>
          Role
        </span>

        <strong>
          <?= e($primaryRole) ?>
        </strong>

      </div>


      <div class="membership-item">

        <span>
          Access
        </span>

        <strong>
          <?= $hasFullAccess
              ? 'Full Access'
              : 'Free Access'
          ?>
        </strong>

      </div>


      <div class="membership-item">

        <span>
          Access Source
        </span>

        <strong>
          <?= e($accessSource) ?>
        </strong>

      </div>


      <div class="membership-item">

        <span>
          Access Through
        </span>

        <strong>
          <?= e($accessThrough) ?>
        </strong>

      </div>

    </div>

  </section>


  <!-- =====================================================
       COMPLIMENTARY GRANT
       ===================================================== -->

  <?php if (
      $hasComplimentaryGrant
  ): ?>

    <section class="membership-card">

      <span
        class="
          membership-pill
          membership-pill--gift
        "
      >

        <i
          class="fa-solid fa-gift"
          aria-hidden="true"
        ></i>

        Complimentary Membership

      </span>


      <h2>
        Complimentary Access
      </h2>


      <p>
        This access was granted independently of any paid
        Stripe subscription.
      </p>


      <div class="membership-grid">

        <div class="membership-item">

          <span>
            Started
          </span>

          <strong>
            <?= e(
                $complimentaryStarted
            ) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Access Through
          </span>

          <strong>
            <?= e(
                $complimentaryEnds
            ) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Access Level
          </span>

          <strong>
            Full Llama Scout Access
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Reason
          </span>

          <strong>
            <?= e(
                $activeComplimentaryGrant[
                    'reason'
                ]
                ?:
                'Complimentary access'
            ) ?>
          </strong>

        </div>

      </div>

    </section>

  <?php endif; ?>


  <!-- =====================================================
       SCOUT STATUS
       ===================================================== -->

  <?php if (
      $hasScoutProfile
  ): ?>

    <section class="membership-card">

      <span class="membership-pill">

        <i
          class="fa-solid fa-compass"
          aria-hidden="true"
        ></i>

        <?php if (
            $isScoutExtension
        ): ?>

          30-Day Scout Extension

        <?php else: ?>

          <?= e(
              scout_status_label(
                  $scoutStatus
              )
          ) ?>

        <?php endif; ?>

      </span>


      <h2>
        Scout Status
      </h2>


      <p>
        Scout access is independent of paid membership and
        complimentary membership grants.
      </p>


      <div class="membership-grid">

        <div class="membership-item">

          <span>
            Original Scout Start
          </span>

          <strong>
            <?= e($scoutSince) ?>
          </strong>

        </div>


        <?php if (
            $isScoutExtension
        ): ?>

          <div class="membership-item">

            <span>
              Extension Started
            </span>

            <strong>
              <?= e($extensionStarted) ?>
            </strong>

          </div>


          <div class="membership-item">

            <span>
              Extension Ends
            </span>

            <strong>
              <?= e($extensionEnds) ?>
            </strong>

          </div>

        <?php else: ?>

          <div class="membership-item">

            <span>
              Active Through
            </span>

            <strong>
              <?= e(
                  $scoutActiveThrough
              ) ?>
            </strong>

          </div>


          <div class="membership-item">

            <span>
              Status
            </span>

            <strong>
              <?= e(
                  scout_status_label(
                      $scoutStatus
                  )
              ) ?>
            </strong>

          </div>

        <?php endif; ?>


        <div class="membership-item">

          <span>
            Benefit
          </span>

          <strong>
            <?= $hasActiveScoutAccess
                ? 'Full Llama Scout access'
                : 'Not currently active'
            ?>
          </strong>

        </div>

      </div>

    </section>

  <?php endif; ?>


  <!-- =====================================================
       STRIPE BILLING
       ===================================================== -->

  <?php if (
      $showBillingSection
  ): ?>

    <section class="membership-card">

      <h2>
        Paid Membership & Billing
      </h2>


      <p>
        Your Stripe subscription status and renewal details.
      </p>


      <div class="membership-grid">

        <div class="membership-item">

          <span>
            Membership Status
          </span>

          <strong>
            <?= e($billingStatus) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Plan
          </span>

          <strong>
            <?= e($billingPlan) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Membership Started
          </span>

          <strong>
            <?= e(
                $membershipStarted
            ) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Paid Through
          </span>

          <strong>
            <?= e($paidThrough) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Renewal
          </span>

          <strong>
            <?= e(
                $renewalStatus
            ) ?>
          </strong>

        </div>

      </div>


      <?php if (
          $isStripeMembership
          &&
          $cancelAtPeriodEnd
      ): ?>

        <div class="membership-note">

          <i
            class="fa-solid fa-circle-info"
            aria-hidden="true"
          ></i>

          <div>

            <strong>
              Your paid membership will not renew.
            </strong>

            It remains active through the paid period shown
            above. Any other active access source, such as
            Scout or complimentary access, remains separate.

          </div>

        </div>

      <?php endif; ?>


      <?php if (
          $showBillingPortal
      ): ?>

        <div class="membership-actions">

          <a
            href="billing-portal.php"
            class="membership-button"
          >

            <i
              class="fa-solid fa-credit-card"
              aria-hidden="true"
            ></i>

            Manage Billing

          </a>

        </div>

      <?php endif; ?>


      <div class="membership-billing-disclosure">

        <strong>
          Stripe securely processes membership payments.
        </strong>

        Llama Scout does not collect or store your complete
        payment card information. For membership or billing
        assistance, contact
        <a href="mailto:billing@llamascout.com">
          billing@llamascout.com
        </a>.
        We will do our best to help. Some payment-method,
        authorization, or card issues may need to be resolved
        through Stripe or your financial institution.

      </div>

    </section>

  <?php endif; ?>


  <!-- =====================================================
       CHOOSE MEMBERSHIP
       ===================================================== -->

  <?php if (
      $showMembershipPlans
  ): ?>

    <section class="membership-card">

      <h2>
        Choose Membership
      </h2>


      <p>
        Unlock full Llama Scout access. Monthly and Annual
        memberships include the same features.
      </p>


      <div class="membership-plans">


        <?php if (
            $monthlyOffer
        ): ?>

          <article
            class="
              membership-plan
              <?= $requestedPlan ===
                  LLAMA_MEMBERSHIP_INTERVAL_MONTHLY
                    ? 'is-selected'
                    : ''
              ?>
            "
          >

            <?php if (
                $monthlySaleLabel
            ): ?>

              <span class="membership-plan-badge">
                <?= e(
                    $monthlySaleLabel
                ) ?>
              </span>

            <?php endif; ?>


            <h3>
              Monthly
            </h3>


            <div class="membership-price">

              <?php if (
                  !empty(
                      $monthlyOffer[
                          'on_sale'
                      ]
                  )
              ): ?>

                <del>
                  <?= e(
                      $monthlyRegular
                  ) ?>
                </del>

              <?php endif; ?>

              <?= e(
                  $monthlyPrice
              ) ?>

              <span>
                / month
              </span>

            </div>


            <ul>

              <li>
                Exact place locations
              </li>

              <li>
                Complete sensory details
              </li>

              <li>
                Road and vehicle access details
              </li>

              <li>
                Connectivity information
              </li>

              <li>
                Full warnings, rules, and planning data
              </li>

            </ul>


            <form
              method="post"
              action="checkout.php"
            >

              <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
              >

              <input
                type="hidden"
                name="interval"
                value="monthly"
              >


              <button
                type="submit"
                class="membership-button"
              >
                Choose Monthly
              </button>

            </form>

          </article>

        <?php endif; ?>


        <?php if (
            $annualOffer
        ): ?>

          <article
            class="
              membership-plan
              <?= $requestedPlan ===
                  LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
                    ? 'is-selected'
                    : ''
              ?>
            "
          >

            <?php if (
                $annualSaleLabel
            ): ?>

              <span class="membership-plan-badge">
                <?= e(
                    $annualSaleLabel
                ) ?>
              </span>

            <?php endif; ?>


            <h3>
              Annual
            </h3>


            <div class="membership-price">

              <?php if (
                  !empty(
                      $annualOffer[
                          'on_sale'
                      ]
                  )
              ): ?>

                <del>
                  <?= e(
                      $annualRegular
                  ) ?>
                </del>

              <?php endif; ?>

              <?= e(
                  $annualPrice
              ) ?>

              <span>
                / year (12mo.)
              </span>

            </div>


            <ul>

              <li>
                Everything included with Monthly
              </li>

              <li>
                Same full-access membership
              </li>

              <li>
                One annual renewal
              </li>

              <li>
                Current scheduled promotions apply automatically
              </li>

              <li>
                Billing managed securely through Stripe
              </li>

            </ul>


            <form
              method="post"
              action="checkout.php"
            >

              <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
              >

              <input
                type="hidden"
                name="interval"
                value="annual"
              >


              <button
                type="submit"
                class="membership-button"
              >
                Choose Annual
              </button>

            </form>

          </article>

        <?php endif; ?>


      </div>


      <div class="membership-billing-disclosure">

        <strong>
          Payments are securely processed by Stripe.
        </strong>

        Llama Scout does not collect or store any
        payment card information.

      </div>

    </section>

  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
