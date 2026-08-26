<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   BADGE DETAIL PAGE
   badge.php
   ========================================================= */


require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/community-profiles.php';


start_llama_session();


$db =
    db();


llama_ensure_community_profile_tables(
    $db
);


/* =========================================================
   ESCAPE
   ========================================================= */

function badge_e(
    mixed $value
): string {

    return
        htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
}


/* =========================================================
   BADGE SLUG
   ========================================================= */

$slug =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'slug'
                ]
                ?? ''
            )
        )
    );


if (
    $slug === ''
    ||
    !preg_match(
        '/^[a-z0-9-]+$/',
        $slug
    )
) {

    http_response_code(
        404
    );

    exit(
        'Badge not found.'
    );
}


/* =========================================================
   CANONICAL URL
   ========================================================= */

$canonicalUrl =
    'https://llamascout.com/badges/'
    .
    rawurlencode(
        $slug
    );


$requestUri =
    (string) (
        $_SERVER[
            'REQUEST_URI'
        ]
        ?? ''
    );


if (
    str_starts_with(
        $requestUri,
        '/badge.php'
    )
) {

    header(
        'Location: '
        .
        $canonicalUrl,
        true,
        301
    );

    exit;
}


/* =========================================================
   LOAD BADGE
   ========================================================= */

$badgeStmt =
    $db->prepare(
        '
        SELECT
            id,
            slug,
            name,
            description,
            category,
            source_organization,
            icon,
            award_type,
            threshold_value

        FROM badge_definitions

        WHERE slug = ?
          AND is_active = 1

        LIMIT 1
        '
    );


$badgeStmt->execute([
    $slug
]);


$badge =
    $badgeStmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$badge
) {

    http_response_code(
        404
    );

    exit(
        'Badge not found.'
    );
}


/* =========================================================
   BADGE IMAGE
   ========================================================= */

$badgeImage =
    llama_badge_image_url(
        $slug
    );


/* =========================================================
   BADGE STATS
   ========================================================= */

$earnedStmt =
    $db->prepare(
        '
        SELECT
            COUNT(DISTINCT user_id)

        FROM user_badges

        WHERE badge_id = ?
          AND review_status = \'earned\'
        '
    );


$earnedStmt->execute([
    (int) $badge['id']
]);


$earnedCount =
    (int)
    $earnedStmt->fetchColumn();


$totalStmt =
    $db->query(
        '
        SELECT
            COUNT(*)

        FROM community_profiles
        '
    );


$totalMembers =
    (int)
    $totalStmt->fetchColumn();


$earnedPercent =
    $totalMembers > 0
        ? (
            $earnedCount
            /
            $totalMembers
        )
        *
        100
        : 0;


/* =========================================================
   RARITY
   ========================================================= */

if (
    $earnedCount === 0
) {

    $rarity =
        'Not Yet Earned';

} elseif (
    $earnedPercent <= 1
) {

    $rarity =
        'Legendary';

} elseif (
    $earnedPercent <= 5
) {

    $rarity =
        'Very Rare';

} elseif (
    $earnedPercent <= 15
) {

    $rarity =
        'Rare';

} elseif (
    $earnedPercent <= 40
) {

    $rarity =
        'Uncommon';

} else {

    $rarity =
        'Common';
}


/* =========================================================
   HOW TO EARN
   ========================================================= */

$howToEarn =
    match (
        $slug
    ) {

        'first-contribution' =>
            'Make your first approved contribution to Llama Scout.',

        'first-place' =>
            'Add your first approved place to the Llama Scout community.',

        'first-llama-scout' =>
            'Complete your first approved Llama Scout field visit.',

        'five-places-scouted' =>
            'Llama Scout 5 different places.',

        'ten-places-scouted' =>
            'Llama Scout 10 different places.',

        'twenty-five-places-scouted' =>
            'Llama Scout 25 different places.',

        'fifty-places-scouted' =>
            'Llama Scout 50 different places.',

        'helpful-editor' =>
            'Submit an approved update or correction that improves an existing place.',

        'master-scout' =>
            'Earn Master Scout status in the Llama Scout community.',

        default =>
            match (
                $badge[
                    'award_type'
                ]
                ?? ''
            ) {

                'credential' =>
                    'Awarded for an applicable training or stewardship credential.',

                'automatic' =>
                    'Earned automatically when the badge requirements are met.',

                default =>
                    'Awarded by Llama Scout for meeting the badge requirements.',
            },
    };


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
  <?= badge_e(
      $badge[
          'name'
      ]
  ) ?> Badge | Llama Scout
</title>

<meta
  name="description"
  content="<?= badge_e(
      $badge[
          'description'
      ]
      ??
      'Llama Scout community badge.'
  ) ?>"
>

<link
  rel="canonical"
  href="<?= badge_e(
      $canonicalUrl
  ) ?>"
>

<link
  rel="stylesheet"
  href="/css/style.css"
>

<link
  rel="stylesheet"
  href="/css/community-profile.css"
>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main class="community-profile-page">


  <section class="community-profile-section">


    <div class="badge-detail">


      <div class="badge-detail-art">


        <?php if (
            $badgeImage
        ): ?>

          <img
            src="<?= badge_e(
                $badgeImage
            ) ?>"
            alt="<?= badge_e(
                $badge[
                    'name'
                ]
            ) ?>"
          >

        <?php else: ?>

          <div class="badge-detail-fallback">

            <i
              class="fa-solid <?= badge_e(
                  $badge[
                      'icon'
                  ]
                  ?? 'fa-award'
              ) ?>"
              aria-hidden="true"
            ></i>

          </div>

        <?php endif; ?>


      </div>


      <div class="badge-detail-content">


        <p class="community-profile-level">
          <?= badge_e(
              ucfirst(
                  (string) (
                      $badge[
                          'category'
                      ]
                      ?? 'community'
                  )
              )
          ) ?>
        </p>


        <h1>
          <?= badge_e(
              $badge[
                  'name'
              ]
          ) ?>
        </h1>


        <?php if (
            !empty(
                $badge[
                    'description'
                ]
            )
        ): ?>

          <p>
            <?= badge_e(
                $badge[
                    'description'
                ]
            ) ?>
          </p>

        <?php endif; ?>


                 <?php if (
            !empty(
                $badge[
                    'source_organization'
                ]
            )
        ): ?>

          <p>
            Issued by:
            <strong>
              <?= badge_e(
                  $badge[
                      'source_organization'
                  ]
              ) ?>
            </strong>
          </p>

        <?php endif; ?>

         
        <div class="badge-detail-stats">


          <div>

            <strong>
              <?= $earnedCount ?>
            </strong>

            <span>
              Members Earned
            </span>

          </div>


          <div>

            <strong>
              <?= number_format(
                  $earnedPercent,
                  1
              ) ?>%
            </strong>

            <span>
              Of Community
            </span>

          </div>


          <div>

            <strong>
              <?= badge_e(
                  $rarity
              ) ?>
            </strong>

            <span>
              Rarity
            </span>

          </div>


        </div>


        <h2>
          How to Earn
        </h2>


        <p>
          <?= badge_e(
              $howToEarn
          ) ?>
        </p>


      </div>


    </div>


  </section>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="/js/header.js"
></script>


</body>

</html>
