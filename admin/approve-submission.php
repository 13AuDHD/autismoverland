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
   FIND ACTIVE SCOUT PROFILE
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
     * so the same report cannot be credited twice.
     *
     * Points remain zero until a Scout points system is
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


    /*
     * Current Scout year normally begins one year before
     * active_through.
     */

    $startTimestamp =
        strtotime(
            '-1 year',
            $endTimestamp
        );


    /*
     * During the first Scout year, never allow the year to
     * begin before the Scout actually became a Scout.
     */

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

        throw new RuntimeException(
            'The submission could not be found.'
        );
    }


    $submissionUserId =
        (int)
        $submission[
            'user_id'
        ];


    /* =====================================================
       CREATE OR REUSE PLACE
       ===================================================== */

    if (
        !empty(
            $submission[
                'place_id'
            ]
        )
    ) {

        /*
         * Already published.
         *
         * Do not create a duplicate place.
         */

        $placeId =
            (int)
            $submission[
                'place_id'
            ];

    } else {

        /*
         * publish_place_submission() creates the draft place
         * and marks the submission approved.
         */

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


    /* =====================================================
       SCOUT ACTIVITY CREDIT
       ===================================================== */

    $newScoutCredit =
        false;


    $scoutProgress =
        null;


    $scoutProfile =
        approval_active_scout_profile(
            $db,
            $submissionUserId
        );


    if (
        $scoutProfile
        &&
        (
            $approvedSubmission[
                'status'
            ]
            ?? ''
        )
        ===
        'approved'
        &&
        approval_submission_qualifies(
            $approvedSubmission,
            $scoutProfile
        )
    ) {

        /*
         * Use the actual approval timestamp as occurred_at.
         *
         * This is important if an older approved submission
         * is ever revisited after this feature was added.
         * It prevents a historical approval from being
         * incorrectly credited to the current Scout year.
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

            $approvalOccurredAt =
                date(
                    'Y-m-d H:i:s'
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


    http_response_code(
        500
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
  Submission Publish Error | Llama Scout Admin
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
      The submission was not published.
    </h1>


    <p>

      Nothing was committed to the database.
      The submission is still available for review.

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
