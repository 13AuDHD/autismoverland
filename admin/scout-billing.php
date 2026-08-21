<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';


require_role(
    'admin'
);


start_llama_session();


$user =
    current_user();


$db =
    db();


/* =========================================================
   POST ONLY
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
) {

    http_response_code(
        405
    );

    exit(
        'Method not allowed.'
    );

}


/* =========================================================
   CSRF

   Uses the same token as admin/scout.php.
   ========================================================= */

$expectedToken =
    $_SESSION[
        'admin_scout_csrf'
    ]
    ?? '';


$submittedToken =
    $_POST[
        'csrf_token'
    ]
    ?? '';


if (
    !is_string(
        $expectedToken
    )
    ||
    $expectedToken === ''
    ||
    !is_string(
        $submittedToken
    )
    ||
    !hash_equals(
        $expectedToken,
        $submittedToken
    )
) {

    http_response_code(
        403
    );

    exit(
        'Your session could not be verified.'
    );

}


/* =========================================================
   SCOUT PROFILE
   ========================================================= */

$scoutProfileId =
    (int) (
        $_POST[
            'scout_profile_id'
        ]
        ?? 0
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


$stmt =
    $db->prepare(
        '
        SELECT
            sp.id,
            sp.user_id,
            sp.status,
            u.stripe_subscription_id,
            u.stripe_cancel_at_period_end,
            u.membership_status
        FROM scout_profiles sp
        INNER JOIN users u
          ON u.id = sp.user_id
        WHERE sp.id = ?
        LIMIT 1
        '
    );


$stmt->execute([
    $scoutProfileId
]);


$scout =
    $stmt->fetch(
        PDO::FETCH_ASSOC
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


if (
    (string)
    $scout[
        'status'
    ]
    !==
    'active'
) {

    http_response_code(
        409
    );

    exit(
        'Only an active Scout can receive the Scout billing transition.'
    );

}


$scoutUserId =
    (int)
    $scout[
        'user_id'
    ];


/* =========================================================
   SCHEDULE END OF PAID SUBSCRIPTION
   ========================================================= */

try {

    $result =
        llama_schedule_subscription_end_for_scout(
            $db,
            $scoutUserId
        );


    $reason =
        (string) (
            $result[
                'reason'
            ]
            ?? ''
        );


    $query =
        match (
            $reason
        ) {

            'scheduled' =>
                'billing=scout-scheduled',

            'already_scheduled' =>
                'billing=scout-already-scheduled',

            'already_ended' =>
                'billing=scout-already-ended',

            'no_subscription' =>
                'billing=scout-no-subscription',

            default =>
                'billing=scout-ok',

        };


    header(
        'Location: /admin/scout.php?id='
        . $scoutProfileId
        . '&'
        . $query
    );


    exit;


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Scout billing transition error for Scout profile #'
        . $scoutProfileId
        . ': '
        . $exception
            ->getMessage()
    );


    header(
        'Location: /scout.php?id='
        . $scoutProfileId
        . '&billing=scout-error'
    );


    exit;

}
