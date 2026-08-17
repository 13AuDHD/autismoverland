<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/timezone.php';

require_login();

$user = current_user();


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

  <style>

    body {
      margin: 0;
      min-height: 100vh;
      background: #f4efe6;
      color: #172822;
    }


    .submissions-page {
      width:
        min(
          900px,
          calc(100% - 36px)
        );

      margin: 0 auto;
      padding: 42px 0 70px;
    }


    .account-logo {
      display: block;
      width: min(320px, 80%);
      margin: 0 auto 34px;
    }


    .back-link {
      display: inline-block;
      margin-bottom: 24px;
      color: inherit;
      font-weight: 700;
    }


    .submissions-header {
      margin-bottom: 28px;
    }


    .submissions-header h1 {
      margin: 0 0 8px;

      font-size:
        clamp(
          2rem,
          6vw,
          3.25rem
        );
    }


    .submissions-header p {
      max-width: 680px;
      margin: 0;
      color: #667069;
      line-height: 1.65;
    }


    .submission-success {
      margin-bottom: 26px;
      padding: 18px 20px;
      background: #edf7ef;
      border-left: 5px solid #436d50;
      border-radius: 10px;
    }


    .submission-success strong {
      display: block;
      margin-bottom: 4px;
    }


    .submission-list {
      display: grid;
      gap: 16px;
    }


    .submission-card {
      padding: 22px;
      background: #fff;
      border:
        1px solid rgba(0,0,0,.09);
      border-radius: 12px;
    }


    .submission-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 18px;
    }


    .submission-card h2 {
      margin: 0 0 5px;
      font-size: 1.25rem;
    }


    .submission-meta {
      margin: 0;
      color: #667069;
      font-size: .92rem;
      line-height: 1.5;
    }


    .submission-status {
      flex: 0 0 auto;
      padding: 7px 11px;
      border-radius: 999px;
      font-size: .8rem;
      font-weight: 800;
    }


    .status-pending {
      background: #f4ead8;
      color: #76521e;
    }


    .status-approved {
      background: #e4f1e7;
      color: #315c3c;
    }


    .status-changes {
      background: #f8edcf;
      color: #72591b;
    }


    .status-rejected {
      background: #f7e2df;
      color: #853e37;
    }


    .submission-review,
    .submission-listing {
      margin-top: 18px;
      padding-top: 16px;
      border-top:
        1px solid rgba(0,0,0,.08);
    }


    .submission-review strong,
    .submission-listing strong {
      display: block;
      margin-bottom: 6px;
    }


    .submission-review p,
    .submission-listing p {
      margin: 0;
      color: #667069;
      line-height: 1.6;
      white-space: pre-line;
    }


    .listing-button {
      display: inline-block;

      margin-top: 12px;
      padding: 10px 14px;

      background: #172822;
      color: #fff;

      border-radius: 7px;

      text-decoration: none;
      font-weight: 800;
      font-size: .88rem;
    }


    .listing-state {
      display: inline-block;

      margin-top: 9px;
      padding: 6px 9px;

      background: #f2eee5;
      color: #667069;

      border-radius: 999px;

      font-size: .78rem;
      font-weight: 800;
    }


    .submission-id {
      margin-top: 15px;
      color: #8a908c;
      font-size: .78rem;
    }


    .empty-state {
      padding: 32px;
      background: #fff;
      border:
        1px solid rgba(0,0,0,.09);
      border-radius: 12px;
      text-align: center;
    }


    .empty-state h2 {
      margin: 0 0 8px;
    }


    .empty-state p {
      max-width: 520px;
      margin: 0 auto 22px;
      color: #667069;
      line-height: 1.65;
    }


    .primary-button {
      display: inline-block;
      padding: 13px 18px;
      background: #172822;
      color: #fff;
      border-radius: 7px;
      text-decoration: none;
      font-weight: 800;
    }


    .submissions-footer {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      margin-top: 34px;
      padding-top: 22px;
      border-top:
        1px solid rgba(0,0,0,.12);
    }


    .submissions-footer a {
      color: inherit;
      font-weight: 700;
    }


    @media (max-width: 600px) {

      .submission-card-header {
        display: block;
      }


      .submission-status {
        display: inline-block;
        margin-top: 14px;
      }

    }

  </style>

</head>


<body>

  <main class="submissions-page">


    <a href="https://llamascout.com">

      <img
        src="https://llamascout.com/images/logo.png"
        alt="Llama Scout"
        class="account-logo"
      >

    </a>


    <a
      href="/"
      class="back-link"
    >
      &larr; Back to My Account
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
        isset($_GET['submitted']) &&
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


    <?php if ($submissions): ?>

      <div class="submission-list">

        <?php foreach (
            $submissions as $submission
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
                $submission['status']
                === 'approved'
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
                    View Listing
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

        <h2>
          No submissions yet
        </h2>

        <p>
          When you Scout a Place, you'll be
          able to follow its review status here.
        </p>

        <?php if (
            !empty(
                $user['email_verified_at']
            )
        ): ?>

          <a
            href="scout-place.php"
            class="primary-button"
          >
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
              $user['email_verified_at']
          )
      ): ?>

        <a href="scout-place.php">
          Scout Another Place
        </a>

      <?php endif; ?>

    </footer>


  </main>

</body>

</html>
