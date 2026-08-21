<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_verified_email();
start_llama_session();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user['id'];


/* =========================================================
   TRAINING CONFIGURATION

   When the real training video is ready, upload it and set
   TRAINING_VIDEO_URL below.

   TRAINING_VERSION should be increased whenever required
   Scout training changes enough that existing Scouts should
   complete the new version.
   ========================================================= */

const TRAINING_VERSION =
    '1';


const TRAINING_VIDEO_URL =
    '';


const TRAINING_VIDEO_TITLE =
    'Welcome to the Llama Scout Team';


/* =========================================================
   TEMPORARY TESTING BYPASS

   IMPORTANT:

   true
       Normal Scout candidates can temporarily mark the
       missing training video complete so we can test the
       entire onboarding workflow.

   false
       Only the real video reaching its end can satisfy the
       video-completion requirement.

   CHANGE THIS TO false AFTER ONBOARDING TESTING IS FINISHED.
   ========================================================= */

const ALLOW_TRAINING_TEST_BYPASS =
    true;


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


/* =========================================================
   USER AUTHORITY
   ========================================================= */

$isOwner =
    user_has_role(
        'owner'
    );


$isAdmin =
    user_has_role(
        'admin'
    );


/*
 * Owners/Admins can always use the testing control while
 * developing the onboarding system.
 *
 * While ALLOW_TRAINING_TEST_BYPASS is true, ordinary Scout
 * candidates can also use it.
 */

$canTestTraining =
    ALLOW_TRAINING_TEST_BYPASS
    ||
    $isOwner
    ||
    $isAdmin;


/* =========================================================
   LOAD SCOUT PROFILE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            user_id,
            status,
            invited_at,
            application_started_at,
            application_submitted_at,
            training_started_at,
            training_completed_at,
            approved_at,
            scout_started_at,
            active_through

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
    );


if (
    !$scoutProfile
) {

    http_response_code(
        404
    );


    exit(
        'No Scout profile was found for this account.'
    );

}


$scoutProfileId =
    (int)
    $scoutProfile[
        'id'
    ];


$status =
    (string)
    $scoutProfile[
        'status'
    ];


/* =========================================================
   ROUTE EARLIER STATES
   ========================================================= */

if (
    $status ===
    'invited'
) {

    header(
        'Location: scout-invite.php'
    );


    exit;

}


if (
    $status ===
    'application_started'
) {

    header(
        'Location: scout-application.php'
    );


    exit;

}



/* =========================================================
   ALLOWED TRAINING STATES
   ========================================================= */

$allowedStates = [
    'application_submitted',
    'training',
    'pending_approval',
    'active',
];


if (
    !in_array(
        $status,
        $allowedStates,
        true
    )
) {

    http_response_code(
        403
    );


    exit(
        'Scout training is not currently available for this account.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'scout_training_csrf'
        ]
    )
) {

    $_SESSION[
        'scout_training_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'scout_training_csrf'
    ];


/* =========================================================
   LOAD TRAINING RECORD
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            scout_profile_id,
            user_id,
            training_version,
            video_started_at,
            video_completed_at,
            acknowledged_tools,
            acknowledged_accuracy,
            acknowledged_safety,
            acknowledged_privacy,
            completed_at,
            created_at,
            updated_at

        FROM scout_training

        WHERE scout_profile_id = ?
          AND user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $scoutProfileId,
    $userId
]);


$training =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: null;


/* =========================================================
   CREATE TRAINING RECORD IF NEEDED
   ========================================================= */

if (
    !$training
) {

    $stmt =
        $db->prepare(
            '
            INSERT INTO scout_training
            (
                scout_profile_id,
                user_id,
                training_version
            )
            VALUES
            (
                ?,
                ?,
                ?
            )
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId,
        TRAINING_VERSION
    ]);


    $trainingId =
        (int)
        $db->lastInsertId();


    $training = [
        'id' =>
            $trainingId,

        'scout_profile_id' =>
            $scoutProfileId,

        'user_id' =>
            $userId,

        'training_version' =>
            TRAINING_VERSION,

        'video_started_at' =>
            null,

        'video_completed_at' =>
            null,

        'acknowledged_tools' =>
            0,

        'acknowledged_accuracy' =>
            0,

        'acknowledged_safety' =>
            0,

        'acknowledged_privacy' =>
            0,

        'completed_at' =>
            null,
    ];

}


/* =========================================================
   RESET OUTDATED TRAINING VERSION
   ========================================================= */

if (
    (string) (
        $training[
            'training_version'
        ]
        ?? ''
    )
    !==
    TRAINING_VERSION
) {

    $stmt =
        $db->prepare(
            '
            UPDATE scout_training

            SET
                training_version = ?,
                video_started_at = NULL,
                video_completed_at = NULL,
                acknowledged_tools = 0,
                acknowledged_accuracy = 0,
                acknowledged_safety = 0,
                acknowledged_privacy = 0,
                completed_at = NULL,
                updated_at = CURRENT_TIMESTAMP

            WHERE id = ?
              AND scout_profile_id = ?
              AND user_id = ?
            '
        );


    $stmt->execute([
        TRAINING_VERSION,
        (int) $training['id'],
        $scoutProfileId,
        $userId
    ]);


    $training[
        'training_version'
    ] =
        TRAINING_VERSION;

    $training[
        'video_started_at'
    ] =
        null;

    $training[
        'video_completed_at'
    ] =
        null;

    $training[
        'acknowledged_tools'
    ] =
        0;

    $training[
        'acknowledged_accuracy'
    ] =
        0;

    $training[
        'acknowledged_safety'
    ] =
        0;

    $training[
        'acknowledged_privacy'
    ] =
        0;

    $training[
        'completed_at'
    ] =
        null;
}


/* =========================================================
   MOVE PROFILE INTO TRAINING
   ========================================================= */

if (
    $status ===
    'application_submitted'
) {

    $stmt =
        $db->prepare(
            '
            UPDATE scout_profiles

            SET
                status =
                    \'training\',

                training_started_at =
                    COALESCE(
                        training_started_at,
                        CURRENT_TIMESTAMP
                    ),

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?
              AND user_id = ?
              AND status =
                    \'application_submitted\'
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId
    ]);


    $status =
        'training';

}


/* =========================================================
   REQUEST CONTENT TYPE
   ========================================================= */

$contentType =
    strtolower(
        (string) (
            $_SERVER[
                'CONTENT_TYPE'
            ]
            ?? ''
        )
    );


/* =========================================================
   JSON VIDEO EVENTS

   The real HTML video player uses these actions to record
   when playback begins and when playback reaches the end.
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
    &&
    str_contains(
        $contentType,
        'application/json'
    )
) {

    header(
        'Content-Type: application/json; charset=utf-8'
    );


    $input =
        json_decode(
            file_get_contents(
                'php://input'
            ),
            true
        );


    if (
        !is_array(
            $input
        )
    ) {

        http_response_code(
            400
        );


        echo json_encode([
            'success' =>
                false,

            'message' =>
                'The training request could not be read.'
        ]);


        exit;

    }


    $submittedToken =
        $input[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        http_response_code(
            403
        );


        echo json_encode([
            'success' =>
                false,

            'message' =>
                'Your session could not be verified.'
        ]);


        exit;

    }


    $action =
        trim(
            (string) (
                $input[
                    'action'
                ]
                ?? ''
            )
        );


    /* =====================================================
       VIDEO STARTED
       ===================================================== */

    if (
        $action ===
        'video_started'
    ) {

        try {

            $stmt =
                $db->prepare(
                    '
                    UPDATE scout_training

                    SET
                        video_started_at =
                            COALESCE(
                                video_started_at,
                                CURRENT_TIMESTAMP
                            ),

                        training_version = ?,

                        updated_at =
                            CURRENT_TIMESTAMP

                    WHERE id = ?
                      AND scout_profile_id = ?
                      AND user_id = ?
                    '
                );


            $stmt->execute([
                TRAINING_VERSION,
                (int)
                $training[
                    'id'
                ],
                $scoutProfileId,
                $userId
            ]);


            echo json_encode([
                'success' =>
                    true
            ]);


            exit;


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout training start error: '
                .
                $exception
                    ->getMessage()
            );


            http_response_code(
                500
            );


            echo json_encode([
                'success' =>
                    false,

                'message' =>
                    'Training progress could not be saved.'
            ]);


            exit;

        }

    }


    /* =====================================================
       VIDEO COMPLETED
       ===================================================== */

    if (
        $action ===
        'video_completed'
    ) {

        try {

            $stmt =
                $db->prepare(
                    '
                    UPDATE scout_training

                    SET
                        video_started_at =
                            COALESCE(
                                video_started_at,
                                CURRENT_TIMESTAMP
                            ),

                        video_completed_at =
                            COALESCE(
                                video_completed_at,
                                CURRENT_TIMESTAMP
                            ),

                        training_version = ?,

                        updated_at =
                            CURRENT_TIMESTAMP

                    WHERE id = ?
                      AND scout_profile_id = ?
                      AND user_id = ?
                    '
                );


            $stmt->execute([
                TRAINING_VERSION,
                (int)
                $training[
                    'id'
                ],
                $scoutProfileId,
                $userId
            ]);


            echo json_encode([
                'success' =>
                    true
            ]);


            exit;


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout training completion error: '
                .
                $exception
                    ->getMessage()
            );


            http_response_code(
                500
            );


            echo json_encode([
                'success' =>
                    false,

                'message' =>
                    'Training completion could not be saved.'
            ]);


            exit;

        }

    }


    http_response_code(
        400
    );


    echo json_encode([
        'success' =>
            false,

        'message' =>
            'That training action was not valid.'
    ]);


    exit;

}


/* =========================================================
   CURRENT TRAINING VALUES
   ========================================================= */

$errors =
    [];


$acknowledgedTools =
    !empty(
        $training[
            'acknowledged_tools'
        ]
    );


$acknowledgedAccuracy =
    !empty(
        $training[
            'acknowledged_accuracy'
        ]
    );


$acknowledgedSafety =
    !empty(
        $training[
            'acknowledged_safety'
        ]
    );


$acknowledgedPrivacy =
    !empty(
        $training[
            'acknowledged_privacy'
        ]
    );


$videoCompleted =
    !empty(
        $training[
            'video_completed_at'
        ]
    );


/* =========================================================
   NORMAL FORM POST
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
    &&
    !str_contains(
        $contentType,
        'application/json'
    )
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $errors[] =
            'Your session could not be verified. Reload the page and try again.';

    }


    $action =
        trim(
            (string) (
                $_POST[
                    'action'
                ]
                ?? 'complete'
            )
        );


    /* =====================================================
       TEMPORARY VIDEO TEST BYPASS
       ===================================================== */

    if (
        $action ===
        'test_complete_video'
    ) {

        if (
            !$canTestTraining
        ) {

            $errors[] =
                'The temporary training test control is disabled.';


        } elseif (
            !$errors
        ) {

            try {

                $stmt =
                    $db->prepare(
                        '
                        UPDATE scout_training

                        SET
                            video_started_at =
                                COALESCE(
                                    video_started_at,
                                    CURRENT_TIMESTAMP
                                ),

                            video_completed_at =
                                COALESCE(
                                    video_completed_at,
                                    CURRENT_TIMESTAMP
                                ),

                            training_version = ?,

                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id = ?
                          AND scout_profile_id = ?
                          AND user_id = ?
                        '
                    );


                $stmt->execute([
                    TRAINING_VERSION,
                    (int)
                    $training[
                        'id'
                    ],
                    $scoutProfileId,
                    $userId
                ]);


                header(
                    'Location: scout-training.php?video_tested=1'
                );


                exit;


            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout temporary training bypass error: '
                    .
                    $exception
                        ->getMessage()
                );


                $errors[] =
                    'The temporary video completion could not be saved.';

            }

        }

    }


    /* =====================================================
       COMPLETE TRAINING
       ===================================================== */

    if (
        $action ===
        'complete'
    ) {

        /*
         * Re-read video completion immediately before
         * finalizing training.
         */

        $stmt =
            $db->prepare(
                '
                SELECT
                    video_completed_at

                FROM scout_training

                WHERE id = ?
                  AND scout_profile_id = ?
                  AND user_id = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            (int)
            $training[
                'id'
            ],
            $scoutProfileId,
            $userId
        ]);


        $videoState =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        $videoCompleted =
            !empty(
                $videoState[
                    'video_completed_at'
                ]
            );


        $acknowledgedTools =
            isset(
                $_POST[
                    'acknowledged_tools'
                ]
            );


        $acknowledgedAccuracy =
            isset(
                $_POST[
                    'acknowledged_accuracy'
                ]
            );


        $acknowledgedSafety =
            isset(
                $_POST[
                    'acknowledged_safety'
                ]
            );


        $acknowledgedPrivacy =
            isset(
                $_POST[
                    'acknowledged_privacy'
                ]
            );


        if (
            !$videoCompleted
        ) {

            $errors[] =
                'Watch the Scout training video through the end before continuing.';

        }


        if (
            !$acknowledgedTools
        ) {

            $errors[] =
                'Confirm that you understand the Scout tools are trusted tools and should be used responsibly.';

        }


        if (
            !$acknowledgedAccuracy
        ) {

            $errors[] =
                'Confirm the Scout accuracy expectation.';

        }


        if (
            !$acknowledgedSafety
        ) {

            $errors[] =
                'Confirm the Scout safety expectation.';

        }


        if (
            !$acknowledgedPrivacy
        ) {

            $errors[] =
                'Confirm the Scout privacy expectation.';

        }


        if (
            !$errors
        ) {

            try {

                $db->beginTransaction();


                $stmt =
                    $db->prepare(
                        '
                        UPDATE scout_training

                        SET
                            training_version = ?,

                            acknowledged_tools = 1,
                            acknowledged_accuracy = 1,
                            acknowledged_safety = 1,
                            acknowledged_privacy = 1,

                            completed_at =
                                COALESCE(
                                    completed_at,
                                    CURRENT_TIMESTAMP
                                ),

                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id = ?
                          AND scout_profile_id = ?
                          AND user_id = ?
                        '
                    );


                $stmt->execute([
                    TRAINING_VERSION,
                    (int)
                    $training[
                        'id'
                    ],
                    $scoutProfileId,
                    $userId
                ]);


              $stmt =
                $db->prepare(
                    '
                    UPDATE scout_profiles
                    
                    SET
                        status =
                            CASE
                                WHEN status = \'active\'
                                    THEN \'active\'
                                ELSE \'pending_approval\'
                            END,
                    
                        training_completed_at =
                            COALESCE(
                                training_completed_at,
                                CURRENT_TIMESTAMP
                            ),
                    
                        updated_at =
                            CURRENT_TIMESTAMP
                    
                    WHERE id = ?
                      AND user_id = ?
                      AND status IN (
                          \'application_submitted\',
                          \'training\',
                          \'pending_approval\',
                          \'active\'
                      )
                    '
                );


                $stmt->execute([
                    $scoutProfileId,
                    $userId
                ]);

                if (
                    $stmt->rowCount()
                    !==
                    1
                ) {
                
                    throw new RuntimeException(
                        'Scout onboarding state changed before training could be completed.'
                    );
                }


                $db->commit();


                header(
                    'Location: scout-training.php?submitted=1'
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
                    'Llama Scout training finalization error: '
                    .
                    $exception
                        ->getMessage()
                );


                $errors[] =
                    'Your training completion could not be saved. Please try again.';

            }

        }

    }

}


/* =========================================================
   REFRESH TRAINING STATE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            training_version,
            video_started_at,
            video_completed_at,
            acknowledged_tools,
            acknowledged_accuracy,
            acknowledged_safety,
            acknowledged_privacy,
            completed_at

        FROM scout_training

        WHERE scout_profile_id = ?
          AND user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $scoutProfileId,
    $userId
]);


$training =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: $training;


$videoCompleted =
    !empty(
        $training[
            'video_completed_at'
        ]
    );


$trainingCompleted =
    !empty(
        $training[
            'completed_at'
        ]
    );


/* =========================================================
   REFRESH PROFILE STATE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT status

        FROM scout_profiles

        WHERE id = ?
          AND user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $scoutProfileId,
    $userId
]);


$freshProfile =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    $freshProfile
) {

    $status =
        (string)
        $freshProfile[
            'status'
        ];

}


/* =========================================================
   DISPLAY NAME
   ========================================================= */

$displayName =
    trim(
        (string) (
            $user[
                'display_name'
            ]
            ?:
            $user[
                'username'
            ]
            ?:
            $user[
                'email'
            ]
        )
    );


$videoAvailable =
    TRAINING_VIDEO_URL !== '';


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
    Scout Training | Llama Scout
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

    .scout-training-page {
      padding:
        34px
        18px
        80px;
    }


    .scout-training-shell {
      width:
        min(
          100%,
          980px
        );

      margin:
        0
        auto;
    }


    /* =====================================================
       PROGRESS
       ===================================================== */

    .scout-progress {
      display: grid;

      grid-template-columns:
        repeat(
          5,
          1fr
        );

      gap:
        8px;

      margin-bottom:
        24px;
    }


    .scout-progress-step {
      padding:
        11px
        8px;

      border-radius:
        10px;

      background:
        rgba(
          23,
          40,
          34,
          .06
        );

      text-align:
        center;

      font-size:
        .76rem;

      font-weight:
        700;

      opacity:
        .6;
    }


    .scout-progress-step.done {
      background:
        rgba(
          23,
          40,
          34,
          .1
        );

      opacity:
        .8;
    }


    .scout-progress-step.active {
      background:
        #172822;

      color:
        #fff;

      opacity:
        1;
    }


    /* =====================================================
       HERO
       ===================================================== */

    .scout-training-hero {
      padding:
        clamp(
          30px,
          7vw,
          60px
        );

      border-radius:
        24px;

      background:
        linear-gradient(
          145deg,
          #10211b,
          #1c342a
        );

      color:
        #fff;
    }


    .scout-training-eyebrow {
      display:
        flex;

      align-items:
        center;

      gap:
        8px;

      margin:
        0
        0
        14px;

      color:
        #d9c49a;

      font-size:
        .78rem;

      font-weight:
        800;

      letter-spacing:
        .13em;

      text-transform:
        uppercase;
    }


    .scout-training-hero h1 {
      margin:
        0
        0
        16px;

      color:
        #fff;

      font-size:
        clamp(
          2.1rem,
          6vw,
          4rem
        );

      line-height:
        1;

      letter-spacing:
        -.04em;
    }


    .scout-training-hero p {
      max-width:
        740px;

      margin:
        0;

      color:
        rgba(
          255,
          255,
          255,
          .82
        );

      line-height:
        1.7;
    }


    /* =====================================================
       CARD
       ===================================================== */

    .scout-training-card {
      margin-top:
        24px;

      padding:
        clamp(
          22px,
          5vw,
          36px
        );

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
        );

      border-radius:
        18px;

      background:
        rgba(
          255,
          255,
          255,
          .82
        );
    }


    .scout-training-card h2 {
      margin:
        0
        0
        9px;
    }


    .scout-training-card > p {
      margin:
        0
        0
        22px;

      max-width:
        760px;

      line-height:
        1.65;
    }


    /* =====================================================
       VIDEO
       ===================================================== */

    .scout-video-shell {
      overflow:
        hidden;

      border-radius:
        16px;

      background:
        #08110e;

      box-shadow:
        0
        14px
        34px
        rgba(
          23,
          40,
          34,
          .14
        );
    }


    .scout-training-video {
      display:
        block;

      width:
        100%;

      aspect-ratio:
        16 / 9;

      background:
        #000;
    }


    .scout-video-placeholder {
      display:
        grid;

      place-items:
        center;

      min-height:
        340px;

      padding:
        40px;

      text-align:
        center;

      color:
        rgba(
          255,
          255,
          255,
          .84
        );
    }


    .scout-video-placeholder i {
      display:
        block;

      margin-bottom:
        16px;

      font-size:
        2rem;
    }


    .scout-video-placeholder strong {
      display:
        block;

      margin-bottom:
        8px;

      font-size:
        1.15rem;
    }


    .scout-video-placeholder p {
      max-width:
        520px;

      margin:
        0;

      line-height:
        1.6;

      color:
        rgba(
          255,
          255,
          255,
          .64
        );
    }


    /* =====================================================
       VIDEO STATUS
       ===================================================== */

    .scout-video-status {
      display:
        flex;

      align-items:
        center;

      gap:
        10px;

      margin-top:
        16px;

      padding:
        14px
        16px;

      border-radius:
        12px;

      background:
        rgba(
          23,
          40,
          34,
          .065
        );
    }


    .scout-video-status.complete {
      background:
        rgba(
          31,
          122,
          72,
          .11
        );
    }


    /* =====================================================
       TEMPORARY TEST CONTROL
       ===================================================== */

    .scout-training-test {
      margin-top:
        18px;

      padding:
        18px;

      border:
        1px dashed
        rgba(
          147,
          111,
          48,
          .45
        );

      border-radius:
        12px;

      background:
        rgba(
          217,
          196,
          154,
          .14
        );
    }


    .scout-training-test-heading {
      display:
        flex;

      align-items:
        center;

      gap:
        9px;

      margin-bottom:
        7px;

      font-weight:
        800;
    }


    .scout-training-test p {
      margin:
        0
        0
        13px;

      line-height:
        1.55;
    }


    .scout-training-test small {
      display:
        block;

      margin-top:
        10px;

      line-height:
        1.5;

      opacity:
        .68;
    }


    .scout-test-button {
      padding:
        11px
        15px;

      border:
        0;

      border-radius:
        8px;

      background:
        #5d4b28;

      color:
        #fff;

      font:
        inherit;

      font-weight:
        750;

      cursor:
        pointer;
    }


    /* =====================================================
       ACKNOWLEDGEMENTS
       ===================================================== */

    .scout-training-agreements {
      display:
        grid;

      gap:
        12px;
    }


    .scout-training-agreement {
      display:
        grid;

      grid-template-columns:
        22px
        1fr;

      gap:
        11px;

      padding:
        16px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
        );

      border-radius:
        12px;
    }


    .scout-training-agreement input {
      width:
        18px;

      height:
        18px;

      margin-top:
        2px;
    }


    .scout-training-agreement label {
      line-height:
        1.55;
    }


    /* =====================================================
       ERRORS
       ===================================================== */

    .scout-errors {
      margin-top:
        20px;

      padding:
        16px
        18px;

      border-radius:
        12px;

      background:
        rgba(
          174,
          52,
          52,
          .11
        );
    }


    .scout-errors strong {
      display:
        block;

      margin-bottom:
        8px;
    }


    .scout-errors ul {
      margin:
        0
        0
        0
        18px;

      padding:
        0;
    }


    /* =====================================================
       SUBMIT
       ===================================================== */

    .scout-training-submit {
      display:
        flex;

      justify-content:
        space-between;

      align-items:
        center;

      gap:
        18px;

      margin-top:
        24px;
    }


    .scout-training-submit p {
      max-width:
        590px;

      margin:
        0;

      font-size:
        .86rem;

      line-height:
        1.55;

      opacity:
        .7;
    }


    .scout-training-button {
      display:
        inline-flex;

      align-items:
        center;

      justify-content:
        center;

      gap:
        8px;

      min-height:
        50px;

      padding:
        13px
        22px;

      border:
        0;

      border-radius:
        10px;

      background:
        #172822;

      color:
        #fff;

      font:
        inherit;

      font-weight:
        800;

      cursor:
        pointer;
    }


    .scout-training-button:disabled {
      opacity:
        .45;

      cursor:
        not-allowed;
    }


    /* =====================================================
       PENDING REVIEW
       ===================================================== */

    .scout-pending {
      text-align:
        center;

      padding:
        46px
        24px;
    }


    .scout-pending i {
      margin-bottom:
        16px;

      font-size:
        2.2rem;
    }


    .scout-pending h2 {
      margin:
        0
        0
        10px;
    }


    .scout-pending p {
      max-width:
        620px;

      margin:
        0
        auto;

      line-height:
        1.65;
    }


    @media (
      max-width:
        700px
    ) {

      .scout-progress {
        overflow-x:
          auto;
      }


      .scout-progress-step {
        min-width:
          88px;
      }


      .scout-training-submit {
        flex-direction:
          column;

        align-items:
          stretch;
      }


      .scout-training-button {
        width:
          100%;
      }


      .scout-video-placeholder {
        min-height:
          240px;
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


<main class="scout-training-page">


  <div class="scout-training-shell">


    <!-- ===================================================
         PROGRESS
         =================================================== -->

<?php

$isReviewStage =
    $status ===
    'pending_approval';


$isScoutStage =
    $status ===
    'active';


$currentStep =
    $isScoutStage
        ? 5
        : (
            $isReviewStage
                ? 4
                : 3
        );

?>


<div
  class="scout-progress"
  aria-label="Scout onboarding progress"
>

  <div class="scout-progress-step done">
    Invitation
  </div>

  <div class="scout-progress-step done">
    About You
  </div>

  <div
    class="scout-progress-step
      <?= $currentStep === 3
          ? 'active'
          : 'done'
      ?>"
  >
    Training
  </div>

  <div
    class="scout-progress-step
      <?php
      if ($currentStep === 4) {
          echo 'active';
      } elseif ($currentStep > 4) {
          echo 'done';
      }
      ?>"
  >
    Review
  </div>

  <div
    class="scout-progress-step
      <?= $currentStep === 5
          ? 'active'
          : ''
      ?>"
  >
    Scout
  </div>

</div>

    <!-- ===================================================
         HERO
         =================================================== -->

<section class="scout-training-hero">

  <p class="scout-training-eyebrow">

    <?php if ($isScoutStage): ?>

      <i
        class="fa-solid fa-binoculars"
        aria-hidden="true"
      ></i>

    <?php elseif ($isReviewStage): ?>

      <i
        class="fa-solid fa-clipboard-check"
        aria-hidden="true"
      ></i>

    <?php else: ?>

      <i
        class="fa-solid fa-compass"
        aria-hidden="true"
      ></i>

    <?php endif; ?>

    Step <?= $currentStep ?> of 5

  </p>


  <?php if ($isScoutStage): ?>

    <h1>
      You're a Scout.
    </h1>

    <p>

      Welcome to the Llama Scout team,
      <?= e($displayName) ?>.

      Your onboarding is complete and your Scout access
      is active.

    </p>


  <?php elseif ($isReviewStage): ?>

    <h1>
      Your Scout profile is in review.
    </h1>

    <p>

      You've finished everything we need from you,
      <?= e($displayName) ?>.

      Your introduction and training have been submitted
      to the Llama Scout team for final review.

    </p>


  <?php else: ?>

    <h1>
      Welcome to the Scout team.
    </h1>

    <p>

      <?= e($displayName) ?>, this short orientation covers
      why Llama Scout exists, what makes Scout information
      useful, how to handle trusted tools responsibly, and
      what the path from Scout to Master Scout looks like.

    </p>

  <?php endif; ?>

</section>

    <!-- ===================================================
         PENDING REVIEW
         =================================================== -->

<?php if ($isScoutStage): ?>


  <section class="scout-training-card">

    <div class="scout-pending">

      <i
        class="fa-solid fa-binoculars"
        aria-hidden="true"
      ></i>

      <h2>
        Scout onboarding complete.
      </h2>

      <p>

        You've officially joined the Llama Scout team.

        Your Scout role is active, your complimentary
        membership is active, and you can now use the
        Scout tools available to your account.

      </p>

    </div>

  </section>


<?php elseif ($isReviewStage): ?>


  <section class="scout-training-card">

    <div class="scout-pending">

      <i
        class="fa-solid fa-clock"
        aria-hidden="true"
      ></i>

      <h2>
        Awaiting Scout approval.
      </h2>

      <p>

        Your training is complete and your Scout information
        has been submitted for review.

        There's nothing else you need to do right now.
        Once your Scout profile is approved, your Scout role
        and complimentary membership will activate.

      </p>

    </div>

  </section>


<?php else: ?>


      <!-- =================================================
           VIDEO
           ================================================= -->

      <section class="scout-training-card">


        <h2>
          <?= e(TRAINING_VIDEO_TITLE) ?>
        </h2>


        <p>

          Watch the full orientation before continuing.

          When the finished training video reaches the end,
          Llama Scout will automatically record completion and
          unlock the final acknowledgements below.

        </p>


        <div class="scout-video-shell">


          <?php if ($videoAvailable): ?>


            <video
              id="scout-training-video"
              class="scout-training-video"
              controls
              controlsList="nodownload"
              preload="metadata"
            >

              <source
                src="<?= e(TRAINING_VIDEO_URL) ?>"
                type="video/mp4"
              >

              Your browser does not support HTML5 video.

            </video>


          <?php else: ?>


            <div class="scout-video-placeholder">

              <div>

                <i
                  class="fa-solid fa-film"
                  aria-hidden="true"
                ></i>


                <strong>
                  Training video coming soon
                </strong>


                <p>

                  The Scout training system is ready, but the
                  orientation video has not been produced yet.

                </p>

              </div>

            </div>


          <?php endif; ?>


        </div>


        <div
          id="scout-video-status"
          class="
            scout-video-status
            <?= $videoCompleted
                ? 'complete'
                : ''
            ?>
          "
        >

          <i
            class="<?= $videoCompleted
                ? 'fa-solid fa-circle-check'
                : 'fa-regular fa-circle'
            ?>"
            aria-hidden="true"
          ></i>


          <span>

            <?= $videoCompleted
                ? 'Training video completed.'
                : 'Training video not completed yet.'
            ?>

          </span>

        </div>


        <!-- ===============================================
             TEMPORARY TEST BYPASS
             =============================================== -->

        <?php if (
            $canTestTraining
            &&
            !$videoCompleted
        ): ?>


          <div class="scout-training-test">


            <div class="scout-training-test-heading">

              <i
                class="fa-solid fa-flask"
                aria-hidden="true"
              ></i>

              Temporary onboarding test

            </div>


            <p>

              The real Scout orientation video has not been
              produced yet. For now, this control lets us
              complete the video requirement and test the rest
              of the onboarding process.

            </p>


            <form
              method="post"
              action="scout-training.php"
            >

              <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
              >

              <input
                type="hidden"
                name="action"
                value="test_complete_video"
              >


              <button
                class="scout-test-button"
                type="submit"
              >

                <i
                  class="fa-solid fa-check"
                  aria-hidden="true"
                ></i>

                Mark Video Complete for Testing

              </button>

            </form>


            <small>

              This is a temporary development control and will
              be removed before Scout onboarding is opened for
              normal use.

            </small>


          </div>


        <?php endif; ?>


      </section>


      <!-- =================================================
           ACKNOWLEDGEMENTS
           ================================================= -->

      <form
        method="post"
        action="scout-training.php"
        id="scout-training-form"
      >


        <input
          type="hidden"
          name="csrf_token"
          value="<?= e($csrfToken) ?>"
        >


        <input
          type="hidden"
          name="action"
          value="complete"
        >


        <section class="scout-training-card">


          <h2>
            Before you finish
          </h2>


          <p>

            These aren't meant to be legalese.

            They're the four things that make trusted Scout
            access work without turning the community into a
            mess.

          </p>


          <div class="scout-training-agreements">


            <div class="scout-training-agreement">

              <input
                id="ack-tools"
                name="acknowledged_tools"
                type="checkbox"
                value="1"
                <?= $acknowledgedTools
                    ? 'checked'
                    : ''
                ?>
                required
              >

              <label for="ack-tools">

                <strong>
                  Scout tools are trusted tools.
                </strong>

                I understand that additional Scout tools and
                future permissions are provided because Llama
                Scout trusts me to use them for the community,
                not to misuse access or information.

              </label>

            </div>


            <div class="scout-training-agreement">

              <input
                id="ack-accuracy"
                name="acknowledged_accuracy"
                type="checkbox"
                value="1"
                <?= $acknowledgedAccuracy
                    ? 'checked'
                    : ''
                ?>
                required
              >

              <label for="ack-accuracy">

                <strong>
                  Unknown is better than invented.
                </strong>

                I will report what I actually know, clearly
                distinguish observation from assumptions, and
                avoid filling gaps with guesses.

              </label>

            </div>


            <div class="scout-training-agreement">

              <input
                id="ack-safety"
                name="acknowledged_safety"
                type="checkbox"
                value="1"
                <?= $acknowledgedSafety
                    ? 'checked'
                    : ''
                ?>
                required
              >

              <label for="ack-safety">

                <strong>
                  A Scout Report is never worth getting hurt.
                </strong>

                I will respect closures, private property,
                weather, road conditions, wildlife, and my
                own limits while gathering information.

              </label>

            </div>


            <div class="scout-training-agreement">

              <input
                id="ack-privacy"
                name="acknowledged_privacy"
                type="checkbox"
                value="1"
                <?= $acknowledgedPrivacy
                    ? 'checked'
                    : ''
                ?>
                required
              >

              <label for="ack-privacy">

                <strong>
                  Access comes with privacy responsibility.
                </strong>

                I will protect private member information,
                sensitive locations, unpublished data, and
                any additional information made available
                through Scout tools.

              </label>

            </div>


          </div>


        </section>


        <div class="scout-training-submit">


          <p>

            Completing training sends your Scout information
            into review. Scout access does not activate until
            an authorized Llama Scout reviewer approves it.

          </p>


          <button
            id="complete-training-button"
            class="scout-training-button"
            type="submit"
            <?= !$videoCompleted
                ? 'disabled'
                : ''
            ?>
          >

            Finish Training

            <i
              class="fa-solid fa-arrow-right"
              aria-hidden="true"
            ></i>

          </button>


        </div>


      </form>


    <?php endif; ?>


  </div>


</main>


<script>

"use strict";


const scoutTrainingCsrf =
  <?= json_encode(
      $csrfToken,
      JSON_HEX_TAG
      |
      JSON_HEX_AMP
      |
      JSON_HEX_APOS
      |
      JSON_HEX_QUOT
  ) ?>;


const scoutTrainingVideo =
  document.getElementById(
    "scout-training-video"
  );


const scoutVideoStatus =
  document.getElementById(
    "scout-video-status"
  );


const completeTrainingButton =
  document.getElementById(
    "complete-training-button"
  );


let startRecorded =
  false;


/* =========================================================
   SAVE VIDEO EVENT
   ========================================================= */

async function sendTrainingAction(
  action
) {

  const response =
    await fetch(
      "scout-training.php",
      {

        method:
          "POST",

        headers: {

          "Content-Type":
            "application/json"

        },

        credentials:
          "same-origin",

        body:
          JSON.stringify({

            csrf_token:
              scoutTrainingCsrf,

            action:
              action

          })

      }
    );


  const raw =
    await response.text();


  let result;


  try {

    result =
      JSON.parse(
        raw
      );


  } catch (
    error
  ) {

    console.error(
      "Training progress response:",
      raw
    );


    throw new Error(
      "Training progress could not be saved."
    );

  }


  if (
    !response.ok
    ||
    !result.success
  ) {

    throw new Error(
      result.message
      ||
      "Training progress could not be saved."
    );

  }


  return result;

}


/* =========================================================
   REAL VIDEO PLAYBACK TRACKING
   ========================================================= */

if (
  scoutTrainingVideo
) {

  scoutTrainingVideo.addEventListener(
    "play",
    async () => {

      if (
        startRecorded
      ) {

        return;

      }


      startRecorded =
        true;


      try {

        await sendTrainingAction(
          "video_started"
        );


      } catch (
        error
      ) {

        console.error(
          error
        );


        startRecorded =
          false;

      }

    }
  );


  scoutTrainingVideo.addEventListener(
    "ended",
    async () => {

      if (
        scoutVideoStatus
      ) {

        scoutVideoStatus.innerHTML =
          '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>Saving video completion...</span>';

      }


      try {

        await sendTrainingAction(
          "video_completed"
        );


        if (
          scoutVideoStatus
        ) {

          scoutVideoStatus.classList.add(
            "complete"
          );


          scoutVideoStatus.innerHTML =
            '<i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Training video completed.</span>';

        }


        if (
          completeTrainingButton
        ) {

          completeTrainingButton.disabled =
            false;

        }


      } catch (
        error
      ) {

        console.error(
          error
        );


        if (
          scoutVideoStatus
        ) {

          scoutVideoStatus.innerHTML =
            '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>The video finished, but completion could not be saved. Reload and try again.</span>';

        }

      }

    }
  );

}

</script>


<script src="https://llamascout.com/js/header.js"></script>


</body>

</html>
