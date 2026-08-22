<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


require_login();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user[
        'id'
    ];


/* =========================================================
   SCOUT HISTORY
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            scout_started_at

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
    ?: [];


$scoutStartedAt =
    trim(
        (string) (
            $scoutProfile[
                'scout_started_at'
            ]
            ?? ''
        )
    );


/* =========================================================
   LOAD THIS MEMBER'S NEW-PLACE SUBMISSIONS
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            ps.id,
            ps.place_id,
            ps.place_name,
            ps.source_type,
            ps.status,
            ps.submitted_at,
            ps.updated_at,
            ps.reviewed_at,
            ps.review_notes,

            p.slug AS place_slug,
            p.status AS place_status

        FROM place_submissions ps

        LEFT JOIN places p
          ON p.id =
             ps.place_id

        WHERE ps.user_id = ?

        ORDER BY
            ps.submitted_at DESC,
            ps.id DESC
        '
    );


$stmt->execute([
    $userId
]);


$submissions =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


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


function submission_status_label(
    string $status
): string {

    return match ($status) {

        'pending' =>
            'Pending Review',

        'approved' =>
            'Approved',

        'needs-changes' =>
            'Needs Changes',

        'rejected' =>
            'Not Approved',

        default =>
            ucwords(
                str_replace(
                    [
                        '-',
                        '_',
                    ],
                    ' ',
                    $status
                )
            ),

    };

}


function submission_status_class(
    string $status
): string {

    return match ($status) {

        'approved' =>
            'status-approved',

        'needs-changes' =>
            'status-changes',

        'rejected' =>
            'status-rejected',

        default =>
            'status-pending',

    };

}


function format_submission_date(
    ?string $date
): string {

    global $user;


    if (
        !$date
    ) {

        return '';

    }


    return llama_format_user_datetime(
        $date,
        $user,
        'F j, Y'
    );

}


function place_is_public(
    ?string $status
): bool {

    return in_array(
        (string)
        $status,
        [
            'active',
            'featured',
        ],
        true
    );

}


function submission_is_editable(
    string $status
): bool {

    return in_array(
        $status,
        [
            'needs-changes',
            'rejected',
        ],
        true
    );

}


function submission_is_scout_report(
    array $submission,
    string $scoutStartedAt
): bool {

    if (
        $scoutStartedAt ===
        ''
    ) {

        return false;

    }


    $submittedTimestamp =
        strtotime(
            (string) (
                $submission[
                    'submitted_at'
                ]
                ?? ''
            )
        );


    $scoutStartedTimestamp =
        strtotime(
            $scoutStartedAt
        );


    if (
        $submittedTimestamp ===
        false
        ||
        $scoutStartedTimestamp ===
        false
    ) {

        return false;

    }


    return
        $submittedTimestamp
        >=
        $scoutStartedTimestamp;

}


function submission_type_label(
    array $submission,
    string $scoutStartedAt
): string {

    if (
        submission_is_scout_report(
            $submission,
            $scoutStartedAt
        )
    ) {

        return 'Llama Scout Report';

    }


    /*
     * The legacy database source_type slug
     * "community-scouted" is intentionally retained for
     * compatibility, but it is no longer displayed publicly.
     *
     * Community contributions are not "Llama Scouted" unless
     * a qualifying Scout/Admin/Owner field contribution exists.
     */

    return 'Community Contributed';

}


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
    My New Place Submissions | Llama Scout
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

    .submission-tools {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;

      margin:
        18px
        0
        24px;
    }


    .submission-tools a {
      display: inline-flex;
      align-items: center;
      gap: 7px;

      padding:
        9px
        12px;

      border-radius: 8px;

      background:
        rgba(
          23,
          40,
          34,
          .07
        );

      color: inherit;
      text-decoration: none;
      font-weight: 700;
    }

  </style>

</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="submissions-page">


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


  <header class="submissions-header">

    <h1>
      My New Place Submissions
    </h1>

    <p>
      Track new Places you submitted to Llama Scout and see
      where each one is in the review process.
    </p>

  </header>


  <div class="submission-tools">

    <a href="scout-place.php">

      <i
        class="fa-solid fa-location-dot"
        aria-hidden="true"
      ></i>

      Add a Place

    </a>

    <a href="my-place-updates.php">

      <i
        class="fa-solid fa-pen-to-square"
        aria-hidden="true"
      ></i>

      My Place Updates

    </a>

  </div>


  <?php if (
      isset(
          $_GET[
              'submitted'
          ]
      )
      &&
      $_GET[
          'submitted'
      ] ===
      '1'
  ): ?>

    <div class="submission-success">

      <strong>
        Place submitted
      </strong>

      Your new Place was sent to Llama Scout for review.

    </div>

  <?php endif; ?>


  <?php if (
      isset(
          $_GET[
              'resubmitted'
          ]
      )
      &&
      $_GET[
          'resubmitted'
      ] ===
      '1'
  ): ?>

    <div class="submission-success">

      <strong>
        Changes submitted
      </strong>

      Your revised new-Place submission has been returned to
      Llama Scout for review.

    </div>

  <?php endif; ?>


  <?php if (
      $submissions
  ): ?>

    <div class="submission-list">


      <?php foreach (
          $submissions as
          $submission
      ): ?>

        <?php

        $status =
            (string)
            $submission[
                'status'
            ];


        $submissionTypeLabel =
            submission_type_label(
                $submission,
                $scoutStartedAt
            );

        ?>

        <article class="submission-card">


          <div class="submission-card-header">


            <div>

              <h2>
                <?= e(
                    $submission[
                        'place_name'
                    ]
                ) ?>
              </h2>


              <p class="submission-meta">

                <?= e(
                    $submissionTypeLabel
                ) ?>

                &middot;

                Submitted

                <?= e(
                    format_submission_date(
                        $submission[
                            'submitted_at'
                        ]
                    )
                ) ?>

              </p>

            </div>


            <span
              class="
                submission-status
                <?= e(
                    submission_status_class(
                        $status
                    )
                ) ?>
              "
            >

              <?= e(
                  submission_status_label(
                      $status
                  )
              ) ?>

            </span>


          </div>


          <?php if (
              !empty(
                  $submission[
                      'review_notes'
                  ]
              )
          ): ?>

            <div class="submission-review">

              <strong>
                Llama Scout review
              </strong>

              <p>
                <?= e(
                    $submission[
                        'review_notes'
                    ]
                ) ?>
              </p>

            </div>

          <?php endif; ?>


          <?php if (
              submission_is_editable(
                  $status
              )
          ): ?>

            <div class="submission-listing">

              <strong>

                <?= $status ===
                    'needs-changes'
                        ? 'Changes requested'
                        : 'Want to revise it?'
                ?>

              </strong>


              <p>

                <?= $status ===
                    'needs-changes'
                        ? 'Update the requested information and send the submission back for another review.'
                        : 'You can revise this submission and send a new version back to Llama Scout.'
                ?>

              </p>


              <a
                class="listing-button"
                href="scout-place.php?edit=<?= (int)
                    $submission[
                        'id'
                    ]
                ?>"
              >

                <i
                  class="fa-solid fa-pen-to-square"
                  aria-hidden="true"
                ></i>

                Edit &amp; Resubmit

              </a>

            </div>

          <?php endif; ?>


          <?php if (
              $status ===
              'approved'
              &&
              !empty(
                  $submission[
                      'place_id'
                  ]
              )
          ): ?>

            <div class="submission-listing">

              <strong>
                Published Place
              </strong>


              <?php if (
                  place_is_public(
                      $submission[
                          'place_status'
                      ]
                  )
                  &&
                  !empty(
                      $submission[
                          'place_slug'
                      ]
                  )
              ): ?>

                <p>
                  This submission has been approved and
                  published as a Llama Scout Place.
                </p>


                <a
                  class="listing-button"
                  href="https://llamascout.com/place.php?place=<?= rawurlencode(
                      (string)
                      $submission[
                          'place_slug'
                      ]
                  ) ?>"
                >

                  <i
                    class="fa-solid fa-binoculars"
                    aria-hidden="true"
                  ></i>

                  View Place

                </a>

              <?php else: ?>

                <p>
                  This submission was approved and converted
                  into a Place. It is awaiting publication.
                </p>

                <span class="listing-state">
                  Awaiting Publication
                </span>

              <?php endif; ?>


            </div>

          <?php endif; ?>


          <div class="submission-id">

            New Place Submission

            #<?= (int)
                $submission[
                    'id'
                ]
            ?>

          </div>


        </article>

      <?php endforeach; ?>


    </div>

  <?php else: ?>

    <section class="empty-state">


      <i
        class="fa-regular fa-map"
        aria-hidden="true"
      ></i>


      <h2>
        No new Place submissions yet
      </h2>


      <p>
        When you submit a new Place, you will be able to
        follow its review status here.
      </p>


      <?php if (
          !empty(
              $user[
                  'email_verified_at'
              ]
          )
      ): ?>

        <a
          href="scout-place.php"
          class="primary-button"
        >

          <i
            class="fa-solid fa-location-dot"
            aria-hidden="true"
          ></i>

          Add a Place

        </a>

      <?php endif; ?>


    </section>

  <?php endif; ?>


  <footer class="submissions-footer">

    <a href="/">
      My Account
    </a>

    <a href="my-place-updates.php">
      My Place Updates
    </a>

    <?php if (
        !empty(
            $user[
                'email_verified_at'
            ]
        )
    ): ?>

      <a href="scout-place.php">
        Add Another Place
      </a>

    <?php endif; ?>

  </footer>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
