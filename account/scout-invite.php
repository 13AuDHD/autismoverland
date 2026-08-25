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


function format_invite_date(
    ?string $date
): string {

    if (
        !$date
    ) {

        return '';

    }


    $timestamp =
        strtotime(
            $date
        );


    if (
        $timestamp === false
    ) {

        return '';

    }


    return date(
        'F j, Y',
        $timestamp
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'scout_invite_csrf'
        ]
    )
) {

    $_SESSION[
        'scout_invite_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'scout_invite_csrf'
    ];


/* =========================================================
   LOAD SCOUT PROFILE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            sp.id,
            sp.user_id,
            sp.status,
            sp.invited_at,
            sp.invited_by,
            sp.invitation_expires_at,
            sp.application_started_at,
            sp.application_submitted_at,
            sp.training_started_at,
            sp.training_completed_at,
            sp.approved_at,
            sp.approved_by,
            sp.scout_started_at,
            sp.active_through,
            sp.inactive_at,
            sp.removed_at,
            sp.removal_reason,
            sp.created_at,
            sp.updated_at,

            inviter.display_name
                AS inviter_display_name,

            inviter.username
                AS inviter_username

        FROM scout_profiles sp

        LEFT JOIN users inviter
          ON inviter.id = sp.invited_by

        WHERE sp.user_id = ?

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
        'No Scout invitation was found for this account.'
    );

}


/* =========================================================
   INVITATION STATE
   ========================================================= */

$status =
    (string)
    $scoutProfile[
        'status'
    ];


$expiresAt =
    $scoutProfile[
        'invitation_expires_at'
    ]
    ?? null;


$isExpired =
    false;


if (
    $status === 'invited'
    &&
    $expiresAt
) {

    $expiresTimestamp =
        strtotime(
            (string)
            $expiresAt
        );


    if (
        $expiresTimestamp !== false
        &&
        $expiresTimestamp <
        time()
    ) {

        $isExpired =
            true;

    }

}


$inviterName =
    trim(
        (string) (
            $scoutProfile[
                'inviter_display_name'
            ]
            ??
            $scoutProfile[
                'inviter_username'
            ]
            ??
            ''
        )
    );


/* =========================================================
   POST ACTIONS
   ========================================================= */

$message =
    '';


$error =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
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

        $error =
            'Your session could not be verified. Reload the page and try again.';


    } else {

        /*
         * Reload immediately before changing anything so we
         * never act on stale invitation status.
         */

        $check =
            $db->prepare(
                '
                SELECT
                    id,
                    status,
                    invitation_expires_at

                FROM scout_profiles

                WHERE id = ?
                  AND user_id = ?

                LIMIT 1
                '
            );


        $check->execute([
            (int)
            $scoutProfile[
                'id'
            ],
            $userId
        ]);


        $freshProfile =
            $check->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$freshProfile
        ) {

            $error =
                'Your Scout invitation could not be found.';


        } else {

            $freshStatus =
                (string)
                $freshProfile[
                    'status'
                ];


            $freshExpired =
                false;


            if (
                $freshStatus ===
                'invited'
                &&
                !empty(
                    $freshProfile[
                        'invitation_expires_at'
                    ]
                )
            ) {

                $freshExpires =
                    strtotime(
                        (string)
                        $freshProfile[
                            'invitation_expires_at'
                        ]
                    );


                $freshExpired =
                    $freshExpires !== false
                    &&
                    $freshExpires <
                    time();

            }


            $action =
                trim(
                    (string) (
                        $_POST[
                            'action'
                        ]
                        ?? ''
                    )
                );


            /* =================================================
               ACCEPT
               ================================================= */

            if (
                $action ===
                'accept'
            ) {

                if (
                    $freshStatus !==
                    'invited'
                ) {

                    $error =
                        'This invitation has already been responded to.';


                } elseif (
                    $freshExpired
                ) {

                    $error =
                        'This Scout invitation has expired.';


                } else {

                    try {

                        $update =
                            $db->prepare(
                                '
                                UPDATE scout_profiles

                                SET
                                    status =
                                        \'application_started\',

                                    application_started_at =
                                        COALESCE(
                                            application_started_at,
                                            CURRENT_TIMESTAMP
                                        ),

                                    updated_at =
                                        CURRENT_TIMESTAMP

                                WHERE id = ?
                                  AND user_id = ?
                                  AND status = \'invited\'
                                  AND (
                                  invitation_expires_at IS NULL
                                  OR invitation_expires_at >= CURRENT_TIMESTAMP
                                  )
                                '
                            );


                        $update->execute([
                            (int)
                            $scoutProfile[
                                'id'
                            ],
                            $userId
                        ]);


                        if (
                            $update->rowCount()
                            < 1
                        ) {

                            $error =
                                'The invitation could not be accepted. Reload the page and try again.';


                        } else {

                            header(
                                'Location: scout-application.php'
                            );


                            exit;

                        }


                    } catch (
                        Throwable $exception
                    ) {

                        error_log(
                            'Llama Scout invitation accept error: '
                            .
                            $exception
                                ->getMessage()
                        );


                        $error =
                            'Something went wrong while accepting the invitation.';

                    }

                }


            /* =================================================
               DECLINE
               ================================================= */

            } elseif (
                $action ===
                'decline'
            ) {

                if (
                    $freshStatus !==
                    'invited'
                ) {

                    $error =
                        'This invitation has already been responded to.';


                } else {

                    try {

                        $update =
                            $db->prepare(
                                '
                                UPDATE scout_profiles

                                SET
                                    status =
                                        \'declined\',

                                    updated_at =
                                        CURRENT_TIMESTAMP

                                WHERE id = ?
                                  AND user_id = ?
                                  AND status = \'invited\'
                                '
                            );


                        $update->execute([
                            (int)
                            $scoutProfile[
                                'id'
                            ],
                            $userId
                        ]);


                        if (
                            $update->rowCount()
                            > 0
                        ) {

                            $status =
                                'declined';


                            $message =
                                'Invitation declined. Your regular Llama Scout account has not been changed.';


                        } else {

                            $error =
                                'The invitation could not be updated.';

                        }


                    } catch (
                        Throwable $exception
                    ) {

                        error_log(
                            'Llama Scout invitation decline error: '
                            .
                            $exception
                                ->getMessage()
                        );


                        $error =
                            'Something went wrong while updating the invitation.';

                    }

                }


            } else {

                $error =
                    'That invitation action was not valid.';

            }

        }

    }

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
    Scout Invitation | Llama Scout
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


</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="scout-invite-page">


  <div class="scout-invite-shell">


    <?php if ($message): ?>

      <div
        class="
          scout-invite-notice
          scout-invite-notice--success
        "
      >

        <p>
          <?= e($message) ?>
        </p>

      </div>

    <?php endif; ?>


    <?php if ($error): ?>

      <div
        class="
          scout-invite-notice
          scout-invite-notice--error
        "
      >

        <p>
          <?= e($error) ?>
        </p>

      </div>

    <?php endif; ?>


    <!-- ===================================================
         ACTIVE INVITATION
         =================================================== -->

    <?php if (
        $status === 'invited'
        &&
        !$isExpired
    ): ?>


      <section class="scout-invite-hero">


        <div class="scout-invite-hero-content">


          <p class="scout-invite-eyebrow">

            <i
              class="fa-solid fa-compass"
              aria-hidden="true"
            ></i>

            Scout Team Invitation

          </p>


          <h1>
            You noticed the details.<br>
            We noticed you.
          </h1>


          <p class="scout-invite-lead">

            <?= e($displayName) ?>, you've been invited to
            join the Llama Scout team as a Scout.

            This isn't an open signup. Scout invitations are
            offered to community members whose contributions
            show care, useful observation, and the kind of
            detail that helps somebody know a place before
            they go.

          </p>


          <?php if ($inviterName !== ''): ?>

            <p class="scout-invite-personal">

              Invitation sent by
              <?= e($inviterName) ?>
              on
              <?= e(
                  format_invite_date(
                      $scoutProfile[
                          'invited_at'
                      ]
                  )
              ) ?>.

            </p>

          <?php endif; ?>


        </div>


      </section>


      <section class="scout-free-access">


        <div class="scout-free-access-icon">

          <i
            class="fa-solid fa-ticket"
            aria-hidden="true"
          ></i>

        </div>


        <div>

          <h2>
            Active Scouts get Llama Scout free.
          </h2>

          <p>

            While your Scout status remains active, your
            full Llama Scout membership is complimentary.

            No annual membership payment is required while
            you're meeting the Scout activity requirement.

          </p>

        </div>


      </section>


      <div class="scout-invite-content">


        <!-- ===============================================
             WHAT YOU GET
             =============================================== -->

        <section class="scout-invite-card">


          <h2>
            What being a Scout includes
          </h2>


          <p>

            Scouts are trusted community contributors with
            additional tools, recognition, and access that
            regular members don't have.

          </p>


          <div class="scout-benefit-grid">


            <div class="scout-benefit">

              <i
                class="fa-solid fa-unlock"
                aria-hidden="true"
              ></i>

              <strong>
                Complimentary membership
              </strong>

              <p>

                Full Llama Scout access is included while
                your Scout status remains active.

              </p>

            </div>


            <div class="scout-benefit">

              <i
                class="fa-solid fa-binoculars"
                aria-hidden="true"
              ></i>

              <strong>
                Scout tools
              </strong>

              <p>

                Access additional tools built specifically
                for scouting and maintaining place data.

              </p>

            </div>


            <div class="scout-benefit">

              <i
                class="fa-solid fa-award"
                aria-hidden="true"
              ></i>

              <strong>
                Scout recognition
              </strong>

              <p>

                Earn Scout status, profile recognition,
                activity history, and a path toward
                Master Scout.

              </p>

            </div>


          </div>


        </section>


        <!-- ===============================================
             EXPECTATIONS
             =============================================== -->

        <section class="scout-invite-card">


          <h2>
            The deal is intentionally simple
          </h2>


          <p>

            We don't want scouting to turn into a second job.
            We do want active Scouts to actually scout.

          </p>


          <div class="scout-expectations">


            <div class="scout-expectation">

              <div class="scout-expectation-number">
                1
              </div>

              <div>

                <h3>
                  Learn the Scout tools
                </h3>

                <p>

                  Complete the short Scout orientation and
                  understand the accuracy, safety, and privacy
                  expectations.

                </p>

              </div>

            </div>


            <div class="scout-expectation">

              <div class="scout-expectation-number">
                2
              </div>

              <div>

                <h3>
                  Submit useful Scout Reports
                </h3>

                <p>

                  Scout places you've actually visited and
                  give people enough useful information to
                  understand what they're heading into.

                </p>

              </div>

            </div>


            <div class="scout-expectation">

              <div class="scout-expectation-number">
                3
              </div>

              <div>

                <h3>
                  Stay active
                </h3>

                <p>

                  Complete at least
                  <strong>3 accepted Scout Reports within a rolling 12-month period</strong>
                  to maintain active Scout status and
                  complimentary membership.

                </p>

              </div>

            </div>


          </div>


        </section>


        <!-- ===============================================
             APPLICATION
             =============================================== -->

        <section class="scout-invite-card">


          <h2>
            Interested?
          </h2>


          <p>

            Accepting the invitation does not immediately
            make you a Scout.

            First you'll complete a short application, go
            through Scout training, and submit everything for
            review. Once approved, your Scout role and
            complimentary membership begin.

          </p>


          <div class="scout-invite-status">


            <?php if (
                !empty(
                    $scoutProfile[
                        'invited_at'
                    ]
                )
            ): ?>

              <span class="scout-invite-pill">

                <i
                  class="fa-solid fa-envelope-open-text"
                  aria-hidden="true"
                ></i>

                Invited
                <?= e(
                    format_invite_date(
                        $scoutProfile[
                            'invited_at'
                        ]
                    )
                ) ?>

              </span>

            <?php endif; ?>


            <?php if ($expiresAt): ?>

              <span class="scout-invite-pill">

                <i
                  class="fa-regular fa-clock"
                  aria-hidden="true"
                ></i>

                Respond by
                <?= e(
                    format_invite_date(
                        $expiresAt
                    )
                ) ?>

              </span>

            <?php endif; ?>


          </div>


          <div class="scout-invite-actions">


            <form
              method="post"
              action="scout-invite.php"
            >

              <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
              >

              <input
                type="hidden"
                name="action"
                value="accept"
              >

              <button
                class="scout-accept-button"
                type="submit"
              >

                <i
                  class="fa-solid fa-compass"
                  aria-hidden="true"
                ></i>

                Accept &amp; Begin Application

              </button>

            </form>


            <form
              method="post"
              action="scout-invite.php"
              onsubmit="
                return confirm(
                  'Decline your Scout invitation? You can remain a regular Llama Scout member.'
                );
              "
            >

              <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
              >

              <input
                type="hidden"
                name="action"
                value="decline"
              >

              <button
                class="scout-decline-button"
                type="submit"
              >
                Not Right Now
              </button>

            </form>


          </div>


        </section>


      </div>


      <p class="scout-invite-smallprint">

        Scout participation is a voluntary community
        contributor role and is not employment.

        Scout access and benefits are subject to Llama Scout
        guidelines, continued good standing, and active
        contribution requirements.

      </p>


    <!-- ===================================================
         APPLICATION ALREADY STARTED
         =================================================== -->

    <?php elseif (
        in_array(
            $status,
            [
                'application_started',
                'application_submitted',
                'training',
                'pending_approval'
            ],
            true
        )
    ): ?>


      <section class="scout-invite-hero">

        <div class="scout-invite-hero-content">

          <p class="scout-invite-eyebrow">

            <i
              class="fa-solid fa-compass"
              aria-hidden="true"
            ></i>

            Scout Team

          </p>


          <h1>
            You're already on your way.
          </h1>


          <p class="scout-invite-lead">

            Your Scout invitation has been accepted.

            Pick up where you left off and continue through
            the application and training process.

          </p>

        </div>

      </section>


      <section class="scout-invite-card">


        <div class="scout-closed-state">

          <i
            class="fa-solid fa-route"
            aria-hidden="true"
          ></i>


          <h2>
            Continue your Scout application
          </h2>


          <p>

            Your progress is saved to your account.

          </p>


          <a
            class="scout-continue-button"
            href="scout-application.php"
          >

            Continue Application

            <i
              class="fa-solid fa-arrow-right"
              aria-hidden="true"
            ></i>

          </a>

        </div>


      </section>


    <!-- ===================================================
         ACTIVE SCOUT
         =================================================== -->

    <?php elseif (
        $status === 'active'
    ): ?>


      <section class="scout-invite-hero">

        <div class="scout-invite-hero-content">

          <p class="scout-invite-eyebrow">

            <i
              class="fa-solid fa-compass"
              aria-hidden="true"
            ></i>

            Llama Scout Team

          </p>


          <h1>
            You're a Scout.
          </h1>


          <p class="scout-invite-lead">

            Your Scout application has been approved and your
            Scout access is active.

          </p>

        </div>

      </section>


      <section class="scout-free-access">

        <div class="scout-free-access-icon">

          <i
            class="fa-solid fa-award"
            aria-hidden="true"
          ></i>

        </div>


        <div>

          <h2>
            Scout status active
          </h2>

          <p>

            Your full Llama Scout membership is complimentary
            while you maintain active Scout status.

          </p>

        </div>

      </section>


      <section class="scout-invite-card">


        <div class="scout-closed-state">

          <i
            class="fa-solid fa-binoculars"
            aria-hidden="true"
          ></i>


          <h2>
            Scout tools are ready
          </h2>


          <p>

            Head back to your account to access your Scout
            tools and activity.

          </p>


          <a
            class="scout-continue-button"
            href="/"
          >

            My Account

            <i
              class="fa-solid fa-arrow-right"
              aria-hidden="true"
            ></i>

          </a>

        </div>


      </section>


    <!-- ===================================================
         EXPIRED
         =================================================== -->

    <?php elseif (
        $isExpired
    ): ?>


      <section class="scout-invite-card">


        <div class="scout-closed-state">

          <i
            class="fa-regular fa-clock"
            aria-hidden="true"
          ></i>


          <h2>
            This invitation has expired
          </h2>


          <p>

            Your Scout invitation expired on
            <?= e(
                format_invite_date(
                    $expiresAt
                )
            ) ?>.

            Your regular Llama Scout account and membership
            have not been affected.

          </p>


          <a
            class="scout-continue-button"
            href="/"
          >
            Back to My Account
          </a>

        </div>


      </section>


    <!-- ===================================================
         DECLINED
         =================================================== -->

    <?php elseif (
        $status === 'declined'
    ): ?>


      <section class="scout-invite-card">


        <div class="scout-closed-state">

          <i
            class="fa-regular fa-circle-check"
            aria-hidden="true"
          ></i>


          <h2>
            Invitation declined
          </h2>


          <p>

            No problem. Your regular Llama Scout account
            hasn't changed, and you can keep contributing
            Community Scouted places whenever you want.

          </p>


          <a
            class="scout-continue-button"
            href="/"
          >
            Back to My Account
          </a>

        </div>


      </section>


    <!-- ===================================================
         INACTIVE / REMOVED
         =================================================== -->

    <?php else: ?>


      <section class="scout-invite-card">


        <div class="scout-closed-state">

          <i
            class="fa-solid fa-compass"
            aria-hidden="true"
          ></i>


          <h2>
            Scout status
          </h2>


          <p>

            Your current Scout status is
            <strong>
              <?= e(
                  ucwords(
                      str_replace(
                          '_',
                          ' ',
                          $status
                      )
                  )
              ) ?>
            </strong>.

          </p>


          <a
            class="scout-continue-button"
            href="/"
          >
            Back to My Account
          </a>

        </div>


      </section>


    <?php endif; ?>


  </div>


</main>


<script src="https://llamascout.com/js/header.js"></script>


</body>

</html>
