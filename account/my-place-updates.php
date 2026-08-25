<?php

declare(strict_types=1); 


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/place-updates.php';


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


llama_ensure_place_updates_table(
    $db
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


function my_update_status_label(
    string $status
): string {

    return match ($status) {

        LLAMA_UPDATE_PENDING =>
            'Pending Review',

        LLAMA_UPDATE_NEEDS_CHANGES =>
            'Needs Changes',

        LLAMA_UPDATE_APPROVED =>
            'Approved',

        LLAMA_UPDATE_REJECTED =>
            'Not Approved',

        LLAMA_UPDATE_WITHDRAWN =>
            'Withdrawn',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-',
                    ],
                    ' ',
                    $status
                )
            ),

    };

}


function my_update_status_class(
    string $status
): string {

    return match ($status) {

        LLAMA_UPDATE_APPROVED =>
            'status-approved',

        LLAMA_UPDATE_NEEDS_CHANGES =>
            'status-changes',

        LLAMA_UPDATE_REJECTED =>
            'status-rejected',

        LLAMA_UPDATE_WITHDRAWN =>
            'status-rejected',

        default =>
            'status-pending',

    };

}


function my_update_type_label(
    string $type
): string {

    return match ($type) {

        LLAMA_PLACE_CORRECTION =>
            'Factual Correction',

        default =>
            'Place Update',

    };

}


function my_update_date(
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


function my_update_change_count(
    mixed $json
): int {

    if (
        is_string(
            $json
        )
    ) {

        $json =
            json_decode(
                $json,
                true
            );

    }


    if (
        !is_array(
            $json
        )
    ) {

        return 0;

    }


    return
        llama_update_field_count(
            $json
        );

}


/* =========================================================
   WITHDRAW
   ========================================================= */

start_llama_session();


if (
    empty(
        $_SESSION[
            'my_place_updates_csrf'
        ]
    )
) {

    $_SESSION[
        'my_place_updates_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    (string)
    $_SESSION[
        'my_place_updates_csrf'
    ];


$message =
    '';


$error =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] ===
    'POST'
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

        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        $updateId =
            (int) (
                $_POST[
                    'update_id'
                ]
                ?? 0
            );


        if (
            $action ===
            'withdraw'
            &&
            $updateId > 0
        ) {

            try {

                llama_withdraw_place_update(
                    $db,
                    $updateId,
                    $userId
                );


                $message =
                    'Your Place update was withdrawn.';

            } catch (
                Throwable $exception
            ) {

                $error =
                    $exception
                        ->getMessage();

            }

        }

    }

}


/* =========================================================
   LOAD UPDATES
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            pus.*,

            p.name AS place_name,
            p.slug AS place_slug,
            p.status AS place_status

        FROM place_update_submissions pus

        INNER JOIN places p
          ON p.id = pus.place_id

        WHERE pus.user_id = ?

        ORDER BY
            pus.submitted_at DESC,
            pus.id DESC
        '
    );


$stmt->execute([
    $userId
]);


$updates =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
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

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    My Place Updates | Llama Scout
  </title>


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


<main class="updates-page">


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


  <header class="updates-header">

    <h1>
      My Place Updates
    </h1>

    <p>
      Track updates and factual corrections you have submitted
      for existing Places. If a moderator requests changes,
      you can revise the same update and send it back for
      another review.
    </p>

  </header>


  <?php if (
      $message !== ''
  ): ?>

    <div class="page-message success">
      <?= e(
          $message
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="page-message error">
      <?= e(
          $error
      ) ?>
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

    <div class="page-message success">

      <strong>
        Changes submitted.
      </strong>

      Your revised Place update is back in the moderation
      queue.

    </div>

  <?php endif; ?>


  <?php if (
      $updates
  ): ?>

    <div class="update-list">


      <?php foreach (
          $updates as
          $update
      ): ?>

        <?php

        $status =
            (string)
            $update[
                'status'
            ];


        $changeCount =
            my_update_change_count(
                $update[
                    'proposed_changes'
                ]
            );

        ?>

        <article class="update-card">


          <div class="update-card-header">


            <div>

              <h2>
                <?= e(
                    $update[
                        'place_name'
                    ]
                ) ?>
              </h2>

              <p class="update-meta">

                <?= e(
                    my_update_type_label(
                        (string)
                        $update[
                            'update_type'
                        ]
                    )
                ) ?>

                &middot;

                <?= $changeCount ?>

                field<?= $changeCount === 1
                    ? ''
                    : 's'
                ?>

                &middot; Submitted

                <?= e(
                    my_update_date(
                        $update[
                            'submitted_at'
                        ]
                    )
                ) ?>

                <?php if (
                    !empty(
                        $update[
                            'visited_at'
                        ]
                    )
                ): ?>

                &middot; Visited

                  <?= e(
                      my_update_date(
                          $update[
                              'visited_at'
                          ]
                      )
                  ) ?>

                <?php endif; ?>

              </p>

            </div>


            <span
              class="
                submission-status
                <?= e(
                    my_update_status_class(
                        $status
                    )
                ) ?>
              "
            >

              <?= e(
                  my_update_status_label(
                      $status
                  )
              ) ?>

            </span>


          </div>


          <?php if (
              !empty(
                  $update[
                      'review_notes'
                  ]
              )
          ): ?>

            <div class="update-review">

              <strong>
                Llama Scout review
              </strong>

              <p>
                <?= e(
                    $update[
                        'review_notes'
                    ]
                ) ?>
              </p>

            </div>

          <?php endif; ?>


          <div class="update-actions">


            <?php if (
                $status ===
                LLAMA_UPDATE_NEEDS_CHANGES
            ): ?>

              <a
                class="update-button"
                href="update-place.php?edit=<?= (int)
                    $update[
                        'id'
                    ]
                ?>"
              >

                <i
                  class="fa-solid fa-pen-to-square"
                  aria-hidden="true"
                ></i>

                Revise &amp; Resubmit

              </a>

            <?php endif; ?>


            <?php if (
                in_array(
                    $status,
                    [
                        LLAMA_UPDATE_PENDING,
                        LLAMA_UPDATE_NEEDS_CHANGES,
                    ],
                    true
                )
            ): ?>

              <form
                method="post"
                style="display:inline;"
              >

                <input
                  type="hidden"
                  name="csrf_token"
                  value="<?= e(
                      $csrfToken
                  ) ?>"
                >

                <input
                  type="hidden"
                  name="update_id"
                  value="<?= (int)
                      $update[
                          'id'
                      ]
                  ?>"
                >

                <button
                  class="
                    update-button
                    danger
                  "
                  type="submit"
                  name="action"
                  value="withdraw"
                  onclick="
                    return confirm(
                      'Withdraw this Place update?'
                    );
                  "
                >

                  <i
                    class="fa-solid fa-xmark"
                    aria-hidden="true"
                  ></i>

                  Withdraw

                </button>

              </form>

            <?php endif; ?>


            <?php if (
                !empty(
                    $update[
                        'place_slug'
                    ]
                )
            ): ?>

              <a
                class="
                  update-button
                  secondary
                "
                href="https://llamascout.com/place.php?place=<?= rawurlencode(
                    (string)
                    $update[
                        'place_slug'
                    ]
                ) ?>"
              >

                <i
                  class="fa-solid fa-location-dot"
                  aria-hidden="true"
                ></i>

                View Place

              </a>

            <?php endif; ?>


          </div>


          <?php if (
              $status ===
              LLAMA_UPDATE_APPROVED
              &&
              (int)
              $update[
                  'points_awarded'
              ]
              > 0
          ): ?>

            <div class="update-review">

              <strong>
                <?= (int)
                    $update[
                        'points_awarded'
                    ]
                ?>
                points earned
              </strong>

              These points are part of your lifetime Scout
              contribution record.

            </div>

          <?php endif; ?>


          <div class="update-id">

            Place Update
            #<?= (int)
                $update[
                    'id'
                ]
            ?>

          </div>


        </article>

      <?php endforeach; ?>


    </div>

  <?php else: ?>

    <section class="updates-empty">

      <i
        class="fa-solid fa-pen-to-square"
        aria-hidden="true"
      ></i>

      <h2>
        No Place updates yet
      </h2>

      <p>
        When you update an existing Place or submit a factual
        correction, you will be able to follow its review
        status here.
      </p>

    </section>

  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
