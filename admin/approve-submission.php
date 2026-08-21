<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/place-publisher.php';


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

   This is called only while the approval transaction is open.
   Locking the profile prevents Scout status from changing
   while the same approval is deciding whether Scout credit
   should be recorded.
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
              AND status = \'active\'

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
     * a Scout do not count toward Scout renewal.
     */

    return
        $submittedAt
        >=
        $scoutStartedAt;
}


/* =========================================================
   HELPER
   RECORD SCOUT REPORT CREDIT
   ========================================================= */

function approval_record_scout_activity(
    PDO $db,
    array $scoutProfile,
    int $submissionId,
    int $placeId,
    string $occurredAt
): bool {

    /*
     * INSERT IGNORE works with the unique Scout activity key
     * so the same report cannot be credited more than once.
     *
     * Points remain zero until the Scout points system is
     * deliberately designed.
     */

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


    return
        $stmt->rowCount()
        > 0;
}


/* =========================================================
   HELPER
   CURRENT SCOUT YEAR PROGRESS
   ========================================================= */

function approval_current_scout_year_progress(
    PDO $db,
    array $scoutProfile
): array {

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
            'required' => 3,
            'remaining' => 3,
            'met' => false,
            'year_start' => null,
            'year_end' => null,
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
            'accepted' => 0,
            'required' => 3,
            'remaining' => 3,
            'met' => false,
            'year_start' => null,
            'year_end' => null,
        ];
    }


    $startTimestamp =
        strtotime(
            '-1 year',
            $endTimestamp
        );


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
            3,

        'remaining' =>
            max(
                0,
                3 - $accepted
            ),

        'met' =>
            $accepted >= 3,

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

       Only a currently pending submission may enter the
       approval/publishing workflow.

       needs-changes and rejected submissions must first be
       edited and resubmitted by the member, which returns
       them to pending.
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

       Once a submission has a place_id, it has already been
       converted into a normal Llama Scout Place.

       Future changes belong in the Place editor, not back in
       the submission approval workflow.
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


    /* =====================================================
       LOCK SCOUT PROFILE BEFORE PUBLISHING

       This keeps the Scout-credit decision stable throughout
       the same approval transaction.
       ===================================================== */

    $scoutProfile =
        approval_active_scout_profile(
            $db,
            $submissionUserId
        );


    /* =====================================================
       CREATE DRAFT PLACE

       publish_place_submission() creates the relational Place,
       links the submission, and marks the submission approved.
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


    /* =====================================================
       SCOUT ACTIVITY CREDIT
       ===================================================== */

    $newScoutCredit =
        false;


    $scoutProgress =
        null;


    if (
        $scoutProfile
        &&
        approval_submission_qualifies(
            $approvedSubmission,
            $scoutProfile
        )
    ) {

        /*
         * Credit occurs when the Scout Report is accepted.
         * reviewed_at is written by the publisher during this
         * same transaction.
         */

        $approvalOccurredAt =
            trim(
                (string) (
                    $approvedSubmission[
                        'reviewed_at'
                    ]
                    ?? ''
                )
            );


        if (
            $approvalOccurredAt === ''
        ) {

            throw new RuntimeException(
                'The approval timestamp could not be determined.'
            );
        }


        $newScoutCredit =
            approval_record_scout_activity(
                $db,
                $scoutProfile,
                $submissionId,
                $placeId,
                $approvalOccurredAt
            );


        $scoutProgress =
            approval_current_scout_year_progress(
                $db,
                $scoutProfile
            );
    }


    /* =====================================================
       COMMIT EVERYTHING TOGETHER
       ===================================================== */

    $db->commit();


    /* =====================================================
       REDIRECT TO PLACE EDITOR

       admin.llamascout.com uses /admin as its document root,
       so place.php is the correct Basecamp-relative route.
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
