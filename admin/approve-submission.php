<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/permissions.php';

require_once
    dirname(__DIR__)
    . '/app/place-publisher.php';
require_once
    dirname(__DIR__)
    . '/app/scout-policy.php';

require_once
    dirname(__DIR__)
    . '/app/scout-scoring.php';

require_once
    dirname(__DIR__)
    . '/app/place-contributions.php';

require_once
    dirname(__DIR__)
    . '/app/place-provenance.php';

require_once
    dirname(__DIR__)
    . '/app/place-submissions.php'; 

llama_require_capability(
    LLAMA_CAP_MODERATE_PLACES
);


start_llama_session();


$user =
    current_user();


$moderatorIsFullAdmin =
    user_has_role(
        'admin',
        (int)
        $user[
            'id'
        ]
    );


$db =
    db();


llama_ensure_place_submission_role_column(
    $db
);


/* =========================================================
   POST ONLY
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] !== 'POST'
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
   ========================================================= */

$expectedToken =
    $_SESSION[
        'admin_submission_csrf'
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
        'Your session could not be verified. Reload the submission page and try again.'
    );

}


/* =========================================================
   INPUT
   ========================================================= */

$submissionId =
    (int) (
        $_POST[
            'submission_id'
        ]
        ?? 0
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


if (
    $submissionId < 1
) {

    http_response_code(
        400
    );

    exit(
        'A valid submission is required.'
    );

}


/* =========================================================
   ACTIVE SCOUT PROFILE
   ========================================================= */

function approval_active_scout_profile(
    PDO $db,
    int $userId
): ?array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                status,
                scout_started_at,
                active_through

            FROM scout_profiles

            WHERE user_id = ?

              AND status =
                  \'active\'

            LIMIT 1

            FOR UPDATE
            '
        );


    $stmt->execute([
        $userId
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
   SUBMISSION QUALIFIES FOR ACTIVE SCOUT CREDIT
   ========================================================= */

function approval_submission_qualifies(
    array $submission,
    array $scoutProfile
): bool {

    $submittedAt =
        strtotime(
            (string) (
                $submission[
                    'submitted_at'
                ]
                ?? ''
            )
        );


    $scoutStartedAt =
        strtotime(
            (string) (
                $scoutProfile[
                    'scout_started_at'
                ]
                ?? ''
            )
        );


    if (
        $submittedAt === false
        ||
        $scoutStartedAt === false
    ) {

        return false;

    }


    return
        $submittedAt
        >=
        $scoutStartedAt;

}


/* =========================================================
   FIELD VISIT DATE
   ========================================================= */

function approval_submission_visit_date(
    array $submission
): ?string {

    $raw =
        $submission[
            'submission_data'
        ]
        ?? null;


    if (
        !is_string(
            $raw
        )
        ||
        trim(
            $raw
        ) === ''
    ) {

        return null;

    }


    $place =
        json_decode(
            $raw,
            true
        );


    if (
        !is_array(
            $place
        )
    ) {

        return null;

    }


    $candidates =
        [];


    if (
        isset(
            $place[
                'verification'
            ]
        )
        &&
        is_array(
            $place[
                'verification'
            ]
        )
    ) {

        $candidates[] =
            $place[
                'verification'
            ][
                'visited'
            ]
            ?? null;

        $candidates[] =
            $place[
                'verification'
            ][
                'visitDate'
            ]
            ?? null;

    }


    $candidates[] =
        $place[
            'visitedAt'
        ]
        ?? null;

    $candidates[] =
        $place[
            'visitDate'
        ]
        ?? null;

    $candidates[] =
        $place[
            'dateVisited'
        ]
        ?? null;


    foreach (
        $candidates as
        $candidate
    ) {

        $candidate =
            trim(
                (string)
                $candidate
            );


        if (
            $candidate === ''
        ) {

            continue;

        }


        $timestamp =
            strtotime(
                $candidate
            );


        if (
            $timestamp === false
        ) {

            continue;

        }


        return
            date(
                'Y-m-d H:i:s',
                $timestamp
            );

    }


    return null;

}


/* =========================================================
   RECORD SCOUT ACTIVITY + POINTS
   ========================================================= */

function approval_record_scout_activity(
    PDO $db,
    array $scoutProfile,
    int $submissionId,
    int $placeId,
    int $points,
    string $occurredAt
): array {

    if (
        $points < 0
    ) {

        $points = 0;

    }


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO scout_activity
            (
                scout_profile_id,
                user_id,
                activity_type,
                place_id,
                submission_id,
                points,
                occurred_at
            )

            VALUES
            (
                ?,
                ?,
                \'place_approved\',
                ?,
                ?,
                ?,
                ?
            )
            '
        );


    $stmt->execute([

        (int)
        $scoutProfile[
            'id'
        ],

        (int)
        $scoutProfile[
            'user_id'
        ],

        $placeId,

        $submissionId,

        $points,

        $occurredAt

    ]);


    $isNew =
        $stmt->rowCount()
        > 0;


    $find =
        $db->prepare(
            '
            SELECT
                id,
                points

            FROM scout_activity

            WHERE scout_profile_id = ?

              AND user_id = ?

              AND activity_type =
                  \'place_approved\'

              AND submission_id = ?

            ORDER BY
                id DESC

            LIMIT 1
            '
        );


    $find->execute([

        (int)
        $scoutProfile[
            'id'
        ],

        (int)
        $scoutProfile[
            'user_id'
        ],

        $submissionId

    ]);


    $activity =
        $find->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$activity
    ) {

        throw new RuntimeException(
            'Scout activity credit could not be located after approval.'
        );

    }


    return [

        'id' =>
            (int)
            $activity[
                'id'
            ],

        'points' =>
            (int)
            $activity[
                'points'
            ],

        'new' =>
            $isNew,

    ];

}


/* =========================================================
   CURRENT SCOUT PERIOD PROGRESS
   ========================================================= */

function approval_current_scout_year_progress(
    PDO $db,
    array $scoutProfile
): array {

    $required =
        llama_scout_policy_int(
            $db,
            'annual_new_places_required',
            1
        );


    $periodMonths =
        llama_scout_policy_int(
            $db,
            'scout_period_months',
            1
        );


    $scoutProfileId =
        (int) (
            $scoutProfile[
                'id'
            ]
            ?? 0
        );


    $scoutUserId =
        (int) (
            $scoutProfile[
                'user_id'
            ]
            ?? 0
        );


    $activeThrough =
        trim(
            (string) (
                $scoutProfile[
                    'active_through'
                ]
                ?? ''
            )
        );


    $scoutStartedAt =
        trim(
            (string) (
                $scoutProfile[
                    'scout_started_at'
                ]
                ?? ''
            )
        );


    if (
        $scoutProfileId < 1
        ||
        $scoutUserId < 1
        ||
        $activeThrough === ''
    ) {

        return [
            'accepted' => 0,
            'required' => $required,
            'remaining' => $required,
            'met' => false,
            'year_start' => null,
            'year_end' => null,
        ];

    }


    $yearStart =
        llama_policy_subtract_months(
            $activeThrough,
            $periodMonths
        );


    $startTimestamp =
        strtotime(
            $yearStart
        );


    $endTimestamp =
        strtotime(
            $activeThrough
        );


    if (
        $startTimestamp === false
        ||
        $endTimestamp === false
    ) {

        throw new RuntimeException(
            'Scout qualification period could not be determined.'
        );

    }


    if (
        $scoutStartedAt !== ''
    ) {

        $scoutStartedTimestamp =
            strtotime(
                $scoutStartedAt
            );


        if (
            $scoutStartedTimestamp !== false
            &&
            $scoutStartedTimestamp
            >
            $startTimestamp
        ) {

            $startTimestamp =
                $scoutStartedTimestamp;

        }

    }


    $yearStart =
        date(
            'Y-m-d H:i:s',
            $startTimestamp
        );


    $yearEnd =
        date(
            'Y-m-d H:i:s',
            $endTimestamp
        );


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM scout_activity

            WHERE scout_profile_id = ?

              AND user_id = ?

              AND activity_type =
                  \'place_approved\'

              AND occurred_at >= ?

              AND occurred_at < ?
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $scoutUserId,
        $yearStart,
        $yearEnd
    ]);


    $accepted =
        (int)
        $stmt->fetchColumn();


    return [

        'accepted' =>
            $accepted,

        'required' =>
            $required,

        'remaining' =>
            max(
                0,
                $required
                -
                $accepted
            ),

        'met' =>
            $accepted
            >=
            $required,

        'year_start' =>
            $yearStart,

        'year_end' =>
            $yearEnd,

    ];

}


/* =========================================================
   PUBLISH SUBMISSION
   ========================================================= */

try {

    $db->beginTransaction();


    $checkStmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                role_at_submission,
                place_id,
                place_name,
                source_type,
                status,
                submission_data,
                submitted_at,
                reviewed_at

            FROM place_submissions

            WHERE id = ?

            LIMIT 1

            FOR UPDATE
            '
        );


    $checkStmt->execute([
        $submissionId
    ]);


    $submission =
        $checkStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$submission
    ) {

        throw new DomainException(
            'The submission could not be found.'
        );

    }


    if (
        strtolower(
            trim(
                (string) (
                    $submission[
                        'status'
                    ]
                    ?? ''
                )
            )
        )
        !==
        'pending'
    ) {

        throw new DomainException(
            'This submission is no longer pending review. Reload the submission before taking another action.'
        );

    }


    if (
        !empty(
            $submission[
                'place_id'
            ]
        )
    ) {

        throw new DomainException(
            'This submission is already linked to a Llama Scout Place. Make further changes from the Place editor.'
        );

    }


    $submissionUserId =
        (int)
        $submission[
            'user_id'
        ];


    if (
        $submissionUserId < 1
    ) {

        throw new RuntimeException(
            'The submission is missing its submitting user.'
        );

    }


    $submissionJson =
        (string) (
            $submission[
                'submission_data'
            ]
            ?? ''
        );


    /*
     * Score the report BEFORE publishing it.
     *
     * The score is based on the submitted report exactly as
     * it existed at approval time.
     */

    $reportScore =
        llama_score_new_place_submission(
            $db,
            $submissionJson
        );


    $pointsAwarded =
        max(
            0,
            (int)
            $reportScore[
                'points_awarded'
            ]
        );


    $visitDate =
        approval_submission_visit_date(
            $submission
        );


    $scoutProfile =
        approval_active_scout_profile(
            $db,
            $submissionUserId
        );


    $placeId =
        publish_place_submission(
            $db,
            $submissionId,
            (int)
            $user[
                'id'
            ],
            $reviewNotes !== ''
                ? $reviewNotes
                : null
        );


    if (
        $placeId < 1
    ) {

        throw new RuntimeException(
            'The approved submission did not create a valid Place.'
        );

    }


    $approvedStmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                place_id,
                status,
                submission_data,
                submitted_at,
                reviewed_at

            FROM place_submissions

            WHERE id = ?

            LIMIT 1
            '
        );


    $approvedStmt->execute([
        $submissionId
    ]);


    $approvedSubmission =
        $approvedStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$approvedSubmission
        ||
        (
            $approvedSubmission[
                'status'
            ]
            ?? ''
        )
        !==
        'approved'
        ||
        (int) (
            $approvedSubmission[
                'place_id'
            ]
            ?? 0
        )
        !==
        $placeId
    ) {

        throw new RuntimeException(
            'The approved submission state is invalid.'
        );

    }


    $approvedAt =
        trim(
            (string) (
                $approvedSubmission[
                    'reviewed_at'
                ]
                ?? ''
            )
        );


    if (
        $approvedAt === ''
    ) {

        throw new RuntimeException(
            'The approval timestamp could not be determined.'
        );

    }


    /* =====================================================
       ORIGINAL PROVENANCE
       ===================================================== */

    $roleAtTime =
        strtolower(
            trim(
                (string) (
                    $submission[
                        'role_at_submission'
                    ]
                    ?? ''
                )
            )
        );


    /*
     * Historical submissions created before role snapshots
     * existed remain conservative rather than being assigned
     * whatever role the contributor happens to have today.
     */

    if (
        $roleAtTime === ''
    ) {

        $roleAtTime =
            'user';

    }


    if (
        $roleAtTime ===
        'master_scout'
    ) {

        $roleAtTime =
            'master-scout';

    }


    $originType =
        llama_origin_from_role(
            $roleAtTime
        );


    llama_record_place_provenance(
        $db,
        $placeId,
        $originType,
        $submissionUserId,
        $submissionId,
        (string)
        $approvedSubmission[
            'submitted_at'
        ]
    );


    /* =====================================================
       SCOUT POINTS + CURRENT-PERIOD ACTIVITY

       Lifetime contribution points are based on the
       contributor's role when the Place was submitted.

       Current-period Scout activity is separate. It is only
       recorded when the contributor still has a qualifying
       active Scout profile and the submission belongs to that
       Scout period.

       This prevents moderation delay from erasing lifetime
       points while also preventing an old submission from
       reactivating Scout status or satisfying a later annual
       period.
       ===================================================== */

    $earnedAsScout =
        in_array(
            $roleAtTime,
            [
                'scout',
                'master-scout',
            ],
            true
        );


    $actualContributionPoints =
        $earnedAsScout
            ? $pointsAwarded
            : 0;


    $newScoutCredit =
        false;


    $scoutProgress =
        null;


    $scoutActivityId =
        null;


    if (
        $earnedAsScout
        &&
        $scoutProfile
        &&
        approval_submission_qualifies(
            $approvedSubmission,
            $scoutProfile
        )
    ) {

        $activity =
            approval_record_scout_activity(
                $db,
                $scoutProfile,
                $submissionId,
                $placeId,
                $actualContributionPoints,
                $approvedAt
            );


        $scoutActivityId =
            (int)
            $activity[
                'id'
            ];


        $newScoutCredit =
            (bool)
            $activity[
                'new'
            ];


        $scoutProgress =
            approval_current_scout_year_progress(
                $db,
                $scoutProfile
            );

    }

    /* =====================================================
       PERMANENT CONTRIBUTION HISTORY
       ===================================================== */

    llama_record_place_contribution(
        $db,
        $placeId,
        $submissionUserId,
        LLAMA_CONTRIBUTION_NEW_PLACE,
        LLAMA_CONTRIBUTION_APPROVED,
        $submissionId,
        $scoutActivityId,
        $visitDate,
        (string)
        $approvedSubmission[
            'submitted_at'
        ],
        $approvedAt,
        (int)
        $user[
            'id'
        ],
        $actualContributionPoints,
        null,
        'New Place report completion: '
        .
        $reportScore[
            'completion_percent'
        ]
        .
        '%',
        $roleAtTime,
        $reportScore
    );


    $db->commit();


    /* =====================================================
       REDIRECT
       ===================================================== */

if (
    $moderatorIsFullAdmin
) {

    $redirectUrl =
        'place.php?id='
        .
        rawurlencode(
            (string)
            $placeId
        )
        .
        '&from=submission';

} else {

    $redirectUrl =
        'submissions.php?status=approved&id='
        .
        rawurlencode(
            (string)
            $submissionId
        )
        .
        '&approved=1';

}


    if (
        $newScoutCredit
    ) {

        $redirectUrl .=
            '&scout_credit=1';


        $redirectUrl .=
            '&scout_points='
            .
            rawurlencode(
                (string)
                $actualContributionPoints
            );


        $redirectUrl .=
            '&scout_completion='
            .
            rawurlencode(
                (string)
                $reportScore[
                    'completion_percent'
                ]
            );


        if (
            is_array(
                $scoutProgress
            )
        ) {

            $redirectUrl .=
                '&scout_progress='
                .
                rawurlencode(
                    (string)
                    $scoutProgress[
                        'accepted'
                    ]
                );

        }

    }


    header(
        'Location: '
        .
        $redirectUrl
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
        'Llama Scout submission publish error #'
        .
        $submissionId
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    $isConflict =
        $exception
        instanceof
        DomainException;


    http_response_code(
        $isConflict
            ? 409
            : 500
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
  Submission Publish Error | Llama Scout Basecamp
</title>

<style>

body {
  margin: 0;
  background: #f4efe6;
  color: #172822;
  font-family:
    system-ui,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;
}

main {
  width:
    min(
      700px,
      calc(
        100% - 36px
      )
    );

  margin:
    0
    auto;

  padding:
    50px
    0
    80px;
}

.error {
  padding: 20px;
  background: #fff;
  border-left: 5px solid #a9443d;
  border-radius: 9px;
}

a {
  color: inherit;
  font-weight: 800;
}

</style>

</head>

<body>

<main>

  <div class="error">

    <h1>

      <?= $isConflict
          ? 'The submission changed before approval.'
          : 'The submission was not published.'
      ?>

    </h1>

    <p>
      Nothing from this approval attempt was committed to the database.
    </p>

    <p>

      <?= htmlspecialchars(
          $exception
              ->getMessage(),
          ENT_QUOTES,
          'UTF-8'
      ) ?>

    </p>

    <p>

      <a
        href="submissions.php?status=all&id=<?= $submissionId ?>"
      >
        Return to the submission
      </a>

    </p>

  </div>

</main>

</body>

</html>
<?php

    exit;

}
