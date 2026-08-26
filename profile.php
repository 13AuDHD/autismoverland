<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   COMMUNITY / PUBLIC PROFILE VIEWER
   profile.php
   ========================================================= */


require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/community-profiles.php';

require_once
    __DIR__
    . '/app/place-contributions.php';


start_llama_session();


$db =
    db();


/* =========================================================
   ESCAPE
   ========================================================= */

function profile_e(
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
   USERNAME
   ========================================================= */

$username =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'username'
                ]
                ?? ''
            )
        )
    );


if (
    $username === ''
    ||
    !preg_match(
        '/^[a-z0-9_]{4,16}$/',
        $username
    )
) {

    http_response_code(
        404
    );

    exit(
        'Profile not found.'
    );
}


/* =========================================================
   CANONICAL PROFILE URL
   ========================================================= */

$canonicalUrl =
    'https://llamascout.com/profile/'
    .
    rawurlencode(
        $username
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
        '/profile.php'
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
   FIND USER
   ========================================================= */

$userStmt =
    $db->prepare(
        '
        SELECT
            id,
            username,
            display_name,
            membership_status,
            created_at

        FROM users

        WHERE LOWER(username) = ?

        LIMIT 1
        '
    );


$userStmt->execute([
    $username
]);


$profileUser =
    $userStmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$profileUser
) {

    http_response_code(
        404
    );

    exit(
        'Profile not found.'
    );
}


$userId =
    (int)
    $profileUser[
        'id'
    ];


/* =========================================================
   COMMUNITY PROFILE
   ========================================================= */

llama_ensure_community_profile(
    $db,
    $userId
);


$communityProfile =
    llama_community_profile(
        $db,
        $userId
    );


$isPublic =
    !empty(
        $communityProfile[
            'is_public'
        ]
    );


/* =========================================================
   VISIBILITY
   ========================================================= */

if (
    !$isPublic
    &&
    !is_logged_in()
) {

   $returnUrl =
       'https://llamascout.com/profile/'
       .
       rawurlencode(
           $username
       );


    header(
        'Location: https://account.llamascout.com/login.php?return='
        .
        rawurlencode(
            $returnUrl
        )
    );


    exit;
}


/* =========================================================
   COMMUNITY LEVEL
   ========================================================= */

$communityLevel =
    'Free';


if (
    user_has_role(
        'owner',
        $userId
    )
    ||
    user_has_role(
        'admin',
        $userId
    )
) {

    $communityLevel =
        'Admin';


} elseif (
    user_has_role(
        'master-scout',
        $userId
    )
    ||
    user_has_role(
        'master_scout',
        $userId
    )
) {

    $communityLevel =
        'Master Scout';


} elseif (
    user_has_role(
        'scout',
        $userId
    )
) {

    $communityLevel =
        'Scout';


} elseif (
    user_has_membership(
        $profileUser
    )
    ||
    user_has_role(
        'member',
        $userId
    )
) {

    $communityLevel =
        'Member';
}


/* =========================================================
   CONTRIBUTION STATS
   ========================================================= */

llama_ensure_place_contributions_table(
    $db
);


$statStmt =
    $db->prepare(
        '
        SELECT

            COUNT(
                DISTINCT
                CASE
                    WHEN contribution_type = ?
                    THEN place_id
                END
            )
                AS places_contributed,

            SUM(
                CASE
                    WHEN contribution_type IN (?, ?)
                    THEN 1
                    ELSE 0
                END
            )
                AS updates,

            COUNT(
                DISTINCT
                CASE
                    WHEN
                        visited_at IS NOT NULL

                        AND role_at_time IN
                        (
                            \'scout\',
                            \'master-scout\',
                            \'master_scout\',
                            \'admin\',
                            \'owner\'
                        )

                    THEN place_id
                END
            )
                AS llama_scouted,

            MAX(
                CASE
                    WHEN
                        visited_at IS NOT NULL

                        AND role_at_time IN
                        (
                            \'scout\',
                            \'master-scout\',
                            \'master_scout\',
                            \'admin\',
                            \'owner\'
                        )

                    THEN visited_at
                END
            )
                AS latest_scout

        FROM place_contributions

        WHERE user_id = ?
          AND status = ?
        '
    );


$statStmt->execute([

    LLAMA_CONTRIBUTION_NEW_PLACE,

    LLAMA_CONTRIBUTION_UPDATE,

    LLAMA_CONTRIBUTION_CORRECTION,

    $userId,

    LLAMA_CONTRIBUTION_APPROVED,

]);


$stats =
    $statStmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$placesContributed =
    (int) (
        $stats[
            'places_contributed'
        ]
        ?? 0
    );


$updates =
    (int) (
        $stats[
            'updates'
        ]
        ?? 0
    );


$llamaScouted =
    (int) (
        $stats[
            'llama_scouted'
        ]
        ?? 0
    );


$latestScout =
    trim(
        (string) (
            $stats[
                'latest_scout'
            ]
            ?? ''
        )
    );


/* =========================================================
   IMAGES + BADGES
   ========================================================= */

$primaryImage =
    llama_primary_profile_image(
        $db,
        $userId
    );


$profileImages =
    llama_community_profile_images(
        $db,
        $userId
    );


$primaryImageId =
    (int) (
        $communityProfile[
            'primary_image_id'
        ]
        ?? 0
    );


if (
    $primaryImageId > 0
) {

    $profileImages =
        array_values(
            array_filter(
                $profileImages,
                static function (
                    array $image
                ) use (
                    $primaryImageId
                ): bool {

                    return
                        (int) (
                            $image[
                                'id'
                            ]
                            ?? 0
                        )
                        !==
                        $primaryImageId;
                }
            )
        );
}


/*
 * Keep the public gallery compact and clean.
 */

$profileImages =
    array_slice(
        $profileImages,
        0,
        4
    );


llama_sync_automatic_profile_badges(
    $db,
    $userId
);


$badges =
    llama_user_badges(
        $db,
        $userId
    );


/*
 * Profile header shows the newest six.
 */

$featuredBadges =
    array_slice(
        $badges,
        0,
        6
    );


/* =========================================================
   PROFILE FIELDS
   ========================================================= */

$displayName =
    trim(
        (string) (
            $profileUser[
                'display_name'
            ]
            ?? ''
        )
    );


if (
    $displayName === ''
) {

    $displayName =
        $username;
}


$bio =
    trim(
        (string) (
            $communityProfile[
                'bio'
            ]
            ?? ''
        )
    );


$location =
    trim(
        (string) (
            $communityProfile[
                'location'
            ]
            ?? ''
        )
    );


$squad =
    trim(
        (string) (
            $communityProfile[
                'squad'
            ]
            ?? ''
        )
    );


$campingStyle =
    trim(
        (string) (
            $communityProfile[
                'camping_style'
            ]
            ?? ''
        )
    );


$favoritePlaces =
    trim(
        (string) (
            $communityProfile[
                'favorite_places'
            ]
            ?? ''
        )
    );


$campingMusic =
    trim(
        (string) (
            $communityProfile[
                'favorite_camping_music'
            ]
            ?? ''
        )
    );


$joinedAt =
    trim(
        (string) (
            $profileUser[
                'created_at'
            ]
            ?? ''
        )
    );


$joinedLabel =
    $joinedAt !== ''
        ? date(
            'F Y',
            strtotime(
                $joinedAt
            )
        )
        : '';


/* =========================================================
   LINKS
   ========================================================= */

$linkFields = [

    'website_url' =>
        [
            'Website',
            'fa-globe'
        ],

    'instagram_url' =>
        [
            'Instagram',
            'fa-instagram'
        ],

    'facebook_url' =>
        [
            'Facebook',
            'fa-facebook'
        ],

    'bluesky_url' =>
        [
            'Bluesky',
            'fa-cloud'
        ],

    'youtube_url' =>
        [
            'YouTube',
            'fa-youtube'
        ],

    'tiktok_url' =>
        [
            'TikTok',
            'fa-tiktok'
        ],

    'other_social_url' =>
        [
            'More',
            'fa-link'
        ],

];


$profileLinks =
    [];


foreach (
    $linkFields
    as
    $field =>
    $linkInfo
) {

    $url =
        trim(
            (string) (
                $communityProfile[
                    $field
                ]
                ?? ''
            )
        );


    if (
        $url === ''
    ) {

        continue;
    }


    $profileLinks[] = [

        'label' =>
            $linkInfo[0],

        'icon' =>
            $linkInfo[1],

        'url' =>
            $url,

    ];
}


/* =========================================================
   ROBOTS
   ========================================================= */

$robots =
    $isPublic
        ? 'index,follow'
        : 'noindex,nofollow';


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
  <?= profile_e($displayName) ?> | Llama Scout
</title>

<meta
  name="robots"
  content="<?= profile_e($robots) ?>"
>

<link
  rel="canonical"
  href="<?= profile_e(
      $canonicalUrl
  ) ?>"
>

<meta
  name="description"
  content="<?= profile_e(
      $displayName
      .
      ' on the Llama Scout community.'
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


  <section class="community-profile-hero">


    <img
      class="community-profile-photo"
      src="<?= profile_e(
          $primaryImage
      ) ?>"
      alt="<?= profile_e(
          $displayName
      ) ?> profile photo"
    >


    <div class="community-profile-identity">


      <div class="community-profile-level">
        <?= profile_e(
            $communityLevel
        ) ?>
      </div>


      <h1>
        <?= profile_e(
            $displayName
        ) ?>
      </h1>


      <p class="community-profile-handle">
        @<?= profile_e(
            $username
        ) ?>
      </p>


      <?php if (
          $location !== ''
      ): ?>

        <p class="community-profile-location">

          <i
            class="fa-solid fa-location-dot"
            aria-hidden="true"
          ></i>

          <?= profile_e(
              $location
          ) ?>

        </p>

      <?php endif; ?>


      <?php if (
          $joinedLabel !== ''
      ): ?>

        <p class="community-profile-joined">

          Joined
          <?= profile_e(
              $joinedLabel
          ) ?>

        </p>

      <?php endif; ?>


    </div>


  </section>


  <?php if (
      $bio !== ''
  ): ?>

    <section class="community-profile-section">

      <h2>
        About
      </h2>

      <p class="community-profile-bio">
        <?= nl2br(
            profile_e(
                $bio
            )
        ) ?>
      </p>

    </section>

  <?php endif; ?>


  <section class="community-profile-section">

    <h2>
      Scout Activity
    </h2>


    <div class="community-profile-stats">


      <div class="community-profile-stat">

        <strong>
          <?= $placesContributed ?>
        </strong>

        <span>
          Places Contributed
        </span>

      </div>


      <div class="community-profile-stat">

        <strong>
          <?= $updates ?>
        </strong>

        <span>
          Updates
        </span>

      </div>


      <div class="community-profile-stat">

        <strong>
          <?= $llamaScouted ?>
        </strong>

        <span>
          Llama Scouted
        </span>

      </div>


      <div class="community-profile-stat">

        <strong>

          <?php if (
              $latestScout !== ''
          ): ?>

            <?= profile_e(
                date(
                    'M j, Y',
                    strtotime(
                        $latestScout
                    )
                )
            ) ?>

          <?php else: ?>

            None yet

          <?php endif; ?>

        </strong>

        <span>
          Latest Scout
        </span>

      </div>


    </div>

  </section>


  <?php if (
      $featuredBadges
  ): ?>

    <section class="community-profile-section">

      <h2>
        Badges
      </h2>


      <div class="community-profile-badges">

        <?php foreach (
            $featuredBadges
            as
            $badge
        ): ?>

          <article class="community-profile-badge">

            <i
              class="fa-solid <?= profile_e(
                  $badge[
                      'icon'
                  ]
                  ?? 'fa-award'
              ) ?>"
              aria-hidden="true"
            ></i>


            <div>

              <strong>
                <?= profile_e(
                    $badge[
                        'name'
                    ]
                    ?? ''
                ) ?>
              </strong>


              <?php if (
                  !empty(
                      $badge[
                          'description'
                      ]
                  )
              ): ?>

                <span>
                  <?= profile_e(
                      $badge[
                          'description'
                      ]
                  ) ?>
                </span>

              <?php endif; ?>

            </div>

          </article>

        <?php endforeach; ?>

      </div>

    </section>

  <?php endif; ?>


  <?php if (
      $squad !== ''
      ||
      $campingStyle !== ''
      ||
      $favoritePlaces !== ''
      ||
      $campingMusic !== ''
  ): ?>

    <section class="community-profile-section">

      <h2>
        Around Camp
      </h2>


      <div class="community-profile-details">


        <?php if (
            $squad !== ''
        ): ?>

          <div>

            <span>
              Squad / Club
            </span>

            <strong>
              <?= profile_e(
                  $squad
              ) ?>
            </strong>

          </div>

        <?php endif; ?>


        <?php if (
            $campingStyle !== ''
        ): ?>

          <div>

            <span>
              Camping Style
            </span>

            <strong>
              <?= profile_e(
                  $campingStyle
              ) ?>
            </strong>

          </div>

        <?php endif; ?>


        <?php if (
            $favoritePlaces !== ''
        ): ?>

          <div>

            <span>
              Favorite Kind of Place
            </span>

            <strong>
              <?= profile_e(
                  $favoritePlaces
              ) ?>
            </strong>

          </div>

        <?php endif; ?>


        <?php if (
            $campingMusic !== ''
        ): ?>

          <div>

            <span>
              Camping Soundtrack
            </span>

            <strong>
              <?= profile_e(
                  $campingMusic
              ) ?>
            </strong>

          </div>

        <?php endif; ?>


      </div>

    </section>

  <?php endif; ?>


  <?php if (
      $profileLinks
  ): ?>

    <section class="community-profile-section">

      <h2>
        Around the Internet
      </h2>


      <div class="community-profile-links">

        <?php foreach (
            $profileLinks
            as
            $link
        ): ?>

          <a
            href="<?= profile_e(
                $link[
                    'url'
                ]
            ) ?>"
            target="_blank"
            rel="noopener noreferrer"
          >

            <i
              class="fa-solid <?= profile_e(
                  $link[
                      'icon'
                  ]
              ) ?>"
              aria-hidden="true"
            ></i>

            <?= profile_e(
                $link[
                    'label'
                ]
            ) ?>

          </a>

        <?php endforeach; ?>

      </div>

    </section>

  <?php endif; ?>


  <?php if (
      $profileImages
  ): ?>

    <section class="community-profile-section">

      <h2>
        Photos
      </h2>


      <div class="community-profile-gallery">

        <?php foreach (
            $profileImages
            as
            $image
        ): ?>

          <img
            src="<?= profile_e(
                $image[
                    'image_src'
                ]
            ) ?>"
            alt="<?= profile_e(
                $image[
                    'alt_text'
                ]
                ?? 'Community profile photo'
            ) ?>"
          >

        <?php endforeach; ?>

      </div>

    </section>

  <?php endif; ?>


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
