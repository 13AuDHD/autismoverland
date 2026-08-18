<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/timezone.php';

require_login();

$user =
    current_user();


/* =========================================================
   LOAD THIS MEMBER'S SUBMISSIONS
   ========================================================= */

$stmt =
    db()->prepare(
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
          ON p.id = ps.place_id

        WHERE ps.user_id = ?

        ORDER BY
            ps.submitted_at DESC
        '
    );


$stmt->execute([
    $user['id']
]);


$submissions =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    string $value
): string {

    return htmlspecialchars(
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
                    '-',
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


    if (!$date) {
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
        (string) $status,
        [
            'active',
            'featured',
        ],
        true
    );
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
    My Submissions | Llama Scout
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
      My Submissions
    </h1>

    <p>
      Keep track of the places you've submitted
      to Llama Scout and see where they are in
      the review process.
    </p>

  </header>


  <?php if (
      isset(
          $_GET['submitted']
      )
      &&
      $_GET['submitted'] === '1'
  ): ?>

    <div class="submission-success">

      <strong>
        Place submitted
      </strong>

      Your Community Scouted place was sent
      to Llama Scout for review.

    </div>

  <?php endif; ?>


  <?php if (
      $submissions
  ): ?>


    <div class="submission-list">


      <?php foreach (
          $submissions
          as $submission
      ): ?>


        <article class="submission-card">


          <div class="submission-card-header">


            <div>

              <h2>

                <?= e(
                    (string)
                    $submission[
                        'place_name'
                    ]
                ) ?>

              </h2>


              <p class="submission-meta">

                Community Scouted

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
                        (string)
                        $submission[
                            'status'
                        ]
                    )
                ) ?>
              "
            >

              <?= e(
                  submission_status_label(
                      (string)
                      $submission[
                          'status'
                      ]
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
                    (string)
                    $submission[
                        'review_notes'
                    ]
                ) ?>

              </p>

            </div>


          <?php endif; ?>


          <?php if (
              $submission[
                  'status'
              ] === 'approved'
              &&
              !empty(
                  $submission[
                      'place_id'
                  ]
              )
          ): ?>


            <div class="submission-listing">

              <strong>
                Your listing
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
                  This place is published on Llama Scout.
                </p>


                <a
                  class="listing-button"
                  href="https://llamascout.com/place.html?place=<?= rawurlencode(
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

                  View Scout Report

                </a>


              <?php else: ?>


                <p>
                  Your submission was approved and
                  has been converted into a Llama Scout
                  place. It is awaiting publication.
                </p>


                <span class="listing-state">
                  Awaiting Publication
                </span>


              <?php endif; ?>


            </div>


          <?php endif; ?>


          <div class="submission-id">

            Submission

            #<?= (int)
                $submission['id']
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
        No submissions yet
      </h2>


      <p>
        When you Scout a Place, you'll be
        able to follow its review status here.
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

          Scout a Place

        </a>


      <?php endif; ?>


    </section>


  <?php endif; ?>


  <footer class="submissions-footer">


    <a href="/">
      My Account
    </a>


    <?php if (
        !empty(
            $user[
                'email_verified_at'
            ]
        )
    ): ?>


      <a href="scout-place.php">
        Scout Another Place
      </a>


    <?php endif; ?>


  </footer>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
