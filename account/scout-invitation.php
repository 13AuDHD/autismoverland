<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


require_verified_email();


start_llama_session();


$user =
    current_user();


$db =
    db();


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


function scout_invite_date(
    ?string $date,
    array $user
): string {

    if (
        !$date
    ) {

        return '';

    }


    return llama_format_user_datetime(
        $date,
        $user,
        'M j, Y'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'scout_invitation_csrf'
        ]
    )
) {

    $_SESSION[
        'scout_invitation_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );

}


$csrfToken =
    $_SESSION[
        'scout_invitation_csrf'
    ];


/* =========================================================
   LOAD SCOUT PROFILE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            sp.id,
            sp.status,
            sp.invited_at,
            sp.invitation_expires_at,
            sp.application_started_at,
            sp.application_submitted_at,
            sp.training_started_at,
            sp.training_completed_at,
            sp.approved_at,
            sp.scout_started_at,
            sp.active_through

        FROM scout_profiles sp

        WHERE sp.user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $user[
        'id'
    ]
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
        'You do not currently have a Scout invitation.'
    );

}


/* =========================================================
   HANDLE INVITATION
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
         * Reload status inside the POST request.
         */

        $lock =
            $db->prepare(
                '
                SELECT
                    id,
                    status,
                    invitation_expires_at

                FROM scout_profiles

                WHERE user_id = ?

                LIMIT 1
                '
            );


        $lock->execute([
            $user[
                'id'
            ]
        ]);


        $current =
            $lock->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$current
        ) {

            $error =
                'Your Scout invitation could not be found.';


        } elseif (
            $current[
                'status'
            ] !== 'invited'
        ) {

            $error =
                'This Scout invitation has already been answered.';


        } elseif (
            !empty(
                $current[
                    'invitation_expires_at'
                ]
            )
            &&
            strtotime(
                $current[
                    'invitation_expires_at'
                ]
            ) < time()
        ) {

            $error =
                'This Scout invitation has expired.';


        } else {

            $action =
                trim(
                    (string) (
                        $_POST[
                            'action'
                        ]
                        ?? ''
                    )
                );


            if (
                $action === 'accept'
            ) {

                try {

                    $db->beginTransaction();


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
                                    )

                            WHERE user_id = ?
                              AND status =
                                  \'invited\'
                            '
                        );


                    $update->execute([
                        $user[
                            'id'
                        ]
                    ]);


                    /*
                     * Create the application shell now.
                     */

                    $application =
                        $db->prepare(
                            '
                            INSERT INTO scout_applications
                            (
                                scout_profile_id,
                                user_id,
                                legal_name,
                                address_line_1,
                                city,
                                state_region,
                                postal_code,
                                country
                            )

                            VALUES
                            (
                                ?,
                                ?,
                                \'\',
                                \'\',
                                \'\',
                                \'\',
                                \'\',
                                \'United States\'
                            )

                            ON DUPLICATE KEY UPDATE
                                updated_at =
                                    CURRENT_TIMESTAMP
                            '
                        );


                    $application->execute([
                        $current[
                            'id'
                        ],
                        $user[
                            'id'
                        ]
                    ]);


                    $db->commit();


                    header(
                        'Location: scout-application.php'
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
                        'Llama Scout invitation acceptance error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'Your Scout invitation could not be accepted.';

                }


            } elseif (
                $action === 'decline'
            ) {

                try {

                    $update =
                        $db->prepare(
                            '
                            UPDATE scout_profiles

                            SET status =
                                \'declined\'

                            WHERE user_id = ?
                              AND status =
                                  \'invited\'
                            '
                        );


                    $update->execute([
                        $user[
                            'id'
                        ]
                    ]);


                    $message =
                        'Scout invitation declined. Your regular Llama Scout account has not been changed.';


                    $scout[
                        'status'
                    ] =
                        'declined';


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
                        'Your response could not be saved.';

                }


            } else {

                $error =
                    'Choose whether you want to accept or decline the invitation.';

            }

        }

    }

}


/* =========================================================
   CURRENT STATUS
   ========================================================= */

$status =
    (string)
    $scout[
        'status'
    ];


$isExpired =
    (
        $status === 'invited'
        &&
        !empty(
            $scout[
                'invitation_expires_at'
            ]
        )
        &&
        strtotime(
            $scout[
                'invitation_expires_at'
            ]
        ) < time()
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


<main class="account-shell">


  <a
    href="/"
    class="back-link"
  >

    <i class="fa-solid fa-arrow-left"></i>

    Back to My Account

  </a>


  <header class="account-header">

    <p
      style="
        margin-bottom:8px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.08em;
        font-size:.8rem;
      "
    >
      Scout Team
    </p>

    <h1>
      You're Invited
    </h1>

    <p>
      You've been selected to apply to become
      a Llama Scout.
    </p>

  </header>


  <?php if (
      $message
  ): ?>

    <section
      class="
        account-status
        account-status--verified
      "
    >

      <strong>
        Response saved
      </strong>

      <p>
        <?= e(
            $message
        ) ?>
      </p>

    </section>

  <?php endif; ?>


  <?php if (
      $error
  ): ?>

    <section class="account-status">

      <strong>
        Something needs attention
      </strong>

      <p>
        <?= e(
            $error
        ) ?>
      </p>

    </section>

  <?php endif; ?>


  <?php if (
      $status === 'invited'
      &&
      !$isExpired
  ): ?>


    <section class="account-section">

      <h2>
        What is a Llama Scout?
      </h2>

      <p>
        Scouts are trusted community contributors who
        visit places, document what they find, and help
        keep Llama Scout information useful and accurate.
      </p>

      <p>
        This isn't an advertised job opening and you
        weren't randomly selected. Scout invitations are
        offered to community members whose contributions
        show that they may be a good fit for the Scout team.
      </p>

    </section>


    <section class="account-section">

      <h2>
        What Scouts Get
      </h2>

      <div class="account-dashboard-grid">


        <div class="account-dashboard-card">

          <h3>
            Full Llama Scout Access
          </h3>

          <p>
            Active Scouts receive full membership-level
            access without paying for a subscription.
          </p>

        </div>


        <div class="account-dashboard-card">

          <h3>
            Scout Tools
          </h3>

          <p>
            Access field tools for detailed place scouting,
            verification, updates, and future assignments.
          </p>

        </div>


        <div class="account-dashboard-card">

          <h3>
            Scout Badges
          </h3>

          <p>
            Scout work contributes toward profile badges,
            milestones, and eventual Master Scout status.
          </p>

        </div>


        <div class="account-dashboard-card">

          <h3>
            Community Recognition
          </h3>

          <p>
            Approved Scout work becomes part of your
            contribution history and future public profile.
          </p>

        </div>


      </div>

    </section>


    <section class="account-section">

      <h2>
        What Scouts Commit To
      </h2>

      <p>
        Scouts are expected to submit useful, accurate
        reports based on places they actually visit and
        to follow Llama Scout's field, safety, privacy,
        and community standards.
      </p>

      <p>
        Scout status is active rather than permanent.
        Staying active will eventually depend on a
        reasonable amount of approved Scout work during
        each rolling 12-month period.
      </p>

      <p>
        You'll get the full details before anything
        becomes official.
      </p>

    </section>


    <section class="account-section">

      <h2>
        Invitation Details
      </h2>

      <div class="status-grid">


        <div class="status-item">

          <span>
            Invited
          </span>

          <strong>
            <?= e(
                scout_invite_date(
                    $scout[
                        'invited_at'
                    ],
                    $user
                )
            ) ?>
          </strong>

        </div>


        <div class="status-item">

          <span>
            Invitation Expires
          </span>

          <strong>
            <?= e(
                scout_invite_date(
                    $scout[
                        'invitation_expires_at'
                    ],
                    $user
                )
            ) ?>
          </strong>

        </div>


      </div>

    </section>


    <section class="account-section">

      <h2>
        Your Decision
      </h2>

      <p>
        Accepting the invitation starts the Scout
        application. It does not immediately change
        your role or membership.
      </p>


      <form
        method="post"
        style="
          display:flex;
          flex-wrap:wrap;
          gap:12px;
          margin-top:22px;
        "
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >


        <button
          type="submit"
          name="action"
          value="accept"
          class="account-submit"
        >
          <i class="fa-solid fa-binoculars"></i>

          Accept &amp; Start Application
        </button>


        <button
          type="submit"
          name="action"
          value="decline"
          class="account-submit"
          onclick="return confirm(
            'Decline your Llama Scout invitation?'
          );"
        >
          Decline Invitation
        </button>

      </form>

    </section>


  <?php elseif (
      $isExpired
  ): ?>


    <section class="account-section">

      <h2>
        Invitation Expired
      </h2>

      <p>
        This Scout invitation has expired.
        Your normal Llama Scout account is unchanged.
      </p>

      <p>
        Llama Scout may send you another invitation
        in the future.
      </p>

    </section>


  <?php elseif (
      $status === 'declined'
  ): ?>


    <section class="account-section">

      <h2>
        Invitation Declined
      </h2>

      <p>
        No problem. Your regular Llama Scout account
        and membership are unchanged.
      </p>

    </section>


  <?php else: ?>


    <section class="account-section">

      <h2>
        Scout Process Started
      </h2>

      <p>
        You've already accepted this invitation.
      </p>


      <?php if (
          in_array(
              $status,
              [
                  'application_started',
                  'application_submitted',
              ],
              true
          )
      ): ?>

        <p>
          <a
            href="scout-application.php"
            class="account-submit"
          >
            Continue Scout Application
          </a>
        </p>

      <?php endif; ?>

    </section>


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
