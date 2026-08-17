<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/place-publisher.php';

require_role('admin');

start_llama_session();

$user = current_user();
$db = db();


/* =========================================================
   POST ONLY
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {

    http_response_code(405);

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

    http_response_code(403);

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


if ($submissionId < 1) {

    http_response_code(400);

    exit(
        'A valid submission is required.'
    );
}


/* =========================================================
   CREATE DRAFT PLACE
   ========================================================= */

try {

    $db->beginTransaction();


    /*
     * Lock the submission before publishing so a double
     * click or second request cannot create two places.
     */

    $checkStmt =
        $db->prepare(
            '
            SELECT
                id,
                place_id

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


    if (!$submission) {

        throw new RuntimeException(
            'The submission could not be found.'
        );
    }


    /*
     * If this submission was already published, simply
     * send the admin to the existing place rather than
     * creating a duplicate.
     */

    if (
        !empty(
            $submission[
                'place_id'
            ]
        )
    ) {

        $placeId =
            (int)
            $submission[
                'place_id'
            ];

    } else {

        $placeId =
            publish_place_submission(
                $db,
                $submissionId,
                (int) $user['id'],
                $reviewNotes !== ''
                    ? $reviewNotes
                    : null
            );
    }


    $db->commit();


    /*
     * Approval lands directly on the normal Place editor.
     * The new record is still DRAFT and therefore cannot
     * appear in the public API, Places page, or map until
     * an admin deliberately activates or features it.
     */

    header(
        'Location: place.php?id=' .
        rawurlencode(
            (string) $placeId
        ) .
        '&from=submission'
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
        'Llama Scout submission publish error #' .
        $submissionId .
        ': ' .
        $exception->getMessage()
    );


    http_response_code(500);

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

  margin: 0 auto;

  padding:
    50px 0
    80px;
}

.error {
  padding: 20px;

  background: #fff;

  border-left:
    5px solid #a9443d;

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
      The submission was not published.
    </h1>

    <p>
      Nothing was committed to the database.
      The submission is still available for review.
    </p>

    <p>
      <?= htmlspecialchars(
          $exception->getMessage(),
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
