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
      to L
