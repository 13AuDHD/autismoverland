<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/place-publisher.php';

require_once
    dirname(__DIR__)
    . '/app/scout-policy.php';

require_once
    dirname(__DIR__)
    . '/app/place-contributions.php';

require_once
    dirname(__DIR__)
    . '/app/place-provenance.php';


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
   HELPER
   FIND + LOCK ACTIVE SCOUT PROFILE
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
   HELPER
   DOES SUBMISSION QUALIFY AS SCOUT REPORT?
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


    /*
     * Reports submitted before the person officially became
     * a Scout do not count toward Scout maintenance.
     */

    return
        $submittedAt
        >=
        $scoutStartedAt;

}


/* =========================================================
   HELPER
   EXTRACT VISIT DATE FROM SUBMITTED PLACE DATA

   Older submission data uses:

       verification.visited

   We retain compatibility with that old JSON structure while
   treating the value strictly as a FIELD VISIT DATE.

   No verification claim is made from this value.
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
        trim($raw) === ''
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


    $candidates = [];


    if (
        isset(
            $place['verification']
        )
        &&
        is_array(
            $place['verification']
        )
    ) {

        $candidates[] =
            $place['verification']['visited']
            ?? null;

        $candidates[] =
            $place['verification']['visitDate']
            ?? null;

    }


    $candidates[] =
        $place['visitedAt']
        ?? null;

    $candidates[] =
        $place['visitDate']
        ?? null;

    $candidates[] =
        $place['dateVisited']
        ?? null;


    foreach (
        $candidates
        as
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
   HELPER
   RECORD SCOUT REPORT CREDIT

   Returns:

   [
       "id" => activity row ID,
       "new" => whether this approval created the credit
   ]

   Points remain zero until the report scoring system is
   deliberately activated.
   ========================================================= */

function approval_record_scout_activity(
    PDO $db,
    array $scoutProfile,
    int $submissionId,
    int $placeId,
    string $occurredAt
): array {

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
                0,
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

        $occurredAt

    ]);


    $isNew =
        $stmt->rowCount()
        > 0;


    $find =
        $db->prepare(
            '
            SELECT
                id

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


    $activityId =
        (int)
        $find->fetchColumn();


    if (
        $activityId < 1
    ) {

        throw new RuntimeException(
            'Scout activity credit could not be located after approval.'
        );

    }


    return [

        'id' =>
            $activityId,

        'new' =>
            $isNew,

    ];

}


/* =========================================================
   HELPER
   CURRENT SCOUT PERIOD PROGRESS

   Uses central Scout policy instead of hardcoded values.
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

            'accepted' =>
                0,

            'required' =>
                $required,

            'remaining' =>
                $required,

            'met' =>
                false,

            'year_start' =>
                null,

            'year_end' =>
                null,

        ];

    }


    $endTimestamp =
        strtotime(
            $activeThrough
        );


    if (
        $endTimestamp === false
    ) {

        return [

            'accepted' =>
                0,

            'required' =>
                $required,

            'remaining' =>
                $required,

            'met' =>
                false,

            'year_start' =>
                null,

            'year_end' =>
                null,

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


    if (
        $startTimestamp === false
    ) {

        throw new RuntimeException(
            'Scout period start could not be determined.'
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


    /* =====================================================
       LOCK SUBMISSION
       ===================================================== */

    $checkStmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
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


    /* =====================================================
       APPROVAL STATE GUARD
       ===================================================== */

    $submissionStatus =
        strtolower(
            trim(
                (string) (
                    $submission[
                        'status'
                    ]
                    ?? ''
                )
            )
        );


    if (
        $submissionStatus !==
        'pending'
    ) {

        throw new DomainException(
            'This submission is no longer pending review. Reload the submission before taking another action.'
        );

    }


    /* =====================================================
       PUBLISHED PLACE GUARD
       ===================================================== */

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


    $visitDate =
        approval_submission_visit_date(
            $submission
        );


    /* =====================================================
       LOCK SCOUT PROFILE BEFORE PUBLISHING
       ===================================================== */

    $scoutProfile =
        approval_active_scout_profile(
            $db,
            $submissionUserId
        );


    /* =====================================================
       CREATE DRAFT PLACE
       ===================================================== */

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


    /* =====================================================
       RELOAD APPROVAL STATE
       ===================================================== */

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
    ) {

        throw new RuntimeException(
            'The approved submission could not be reloaded.'
        );

    }


    if (
        (
            $approvedSubmission[
                'status'
            ]
            ?? ''
        )
        !==
        'approved'
    ) {

        throw new RuntimeException(
            'The submission was not marked approved.'
        );

    }


    if (
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
            'The approved submission was not linked to the expected Place.'
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
       ORIGINAL PLACE PROVENANCE

       Determine the contributor's authority at the time the
       new Place was created.

       Examples:

       normal user  -> community
       Scout        -> scout
       Master Scout -> scout
       Admin        -> admin
       Owner        -> owner
       ===================================================== */

    $roleAtTime =
        llama_contribution_role(
            $db,
            $submissionUserId
        );


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
       SCOUT ACTIVITY CREDIT

       Only active Scouts get annual Scout-report credit.

       The resulting activity ID is linked to the permanent
       Place contribution history.

       Points remain zero until scoring is activated.
       ===================================================== */

    $newScoutCredit =
        false;


    $scoutProgress =
        null;


    $scoutActivityId =
        null;


    if (
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
       PERMANENT PLACE CONTRIBUTION

       Every approved new Place gets a contribution row,
       regardless of contributor role.

       This is where the provenance trail actually begins.

       A Scout/Admin/Owner contribution with visited_at set
       will automatically qualify the Place as Llama Scouted
       through place-provenance.php.

       A normal community contribution remains Community
       Contributed.

       points_awarded remains zero until the scoring engine
       is activated.
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
        0,
        null,
        null
    );


    /* =====================================================
       COMMIT EVERYTHING TOGETHER
       ===================================================== */

    $db->commit();


    /* =====================================================
       REDIRECT TO PLACE EDITOR
       ===================================================== */

    $redirectUrl =
        'place.php?id='
        .
        rawurlencode(
            (string)
            $placeId
        )
        .
        '&from=submission';


    if (
        $newScoutCredit
    ) {

        $redirectUrl .=
            '&scout_credit=1';


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


/* =========================================================
   ERROR
   ========================================================= */

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
  padding:
    20px;

  background:
    #fff;

  border-left:
    5px solid #a9443d;

  border-radius:
    9px;
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

      Nothing from this approval attempt was committed to the
      database.

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
