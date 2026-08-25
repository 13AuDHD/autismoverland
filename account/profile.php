<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/mail.php';

require_once
    dirname(__DIR__)
    . '/app/username-policy.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/community-profiles.php';


require_login();

start_llama_session();


$db =
    db();

$user =
    current_user();

$userId =
    (int) (
        $user['id']
        ?? 0
    );


llama_ensure_community_profile(
    $db,
    $userId
);


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'profile_image_csrf'
        ]
    )
) {

    $_SESSION[
        'profile_image_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$profileImageCsrf =
    (string)
    $_SESSION[
        'profile_image_csrf'
    ];


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


function create_email_verification(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();


    try {

        $expireStmt =
            $db->prepare(
                '
                UPDATE email_verifications

                SET used_at =
                    CURRENT_TIMESTAMP

                WHERE user_id = ?
                  AND used_at IS NULL
                '
            );


        $expireStmt->execute([
            $user['id']
        ]);


        $token =
            bin2hex(
                random_bytes(
                    32
                )
            );


        $tokenHash =
            hash(
                'sha256',
                $token
            );


        $insertStmt =
            $db->prepare(
                '
                INSERT INTO email_verifications
                (
                    user_id,
                    token_hash,
                    expires_at
                )

                VALUES
                (
                    ?,
                    ?,
                    DATE_ADD(
                        CURRENT_TIMESTAMP,
                        INTERVAL 24 HOUR
                    )
                )
                '
            );


        $insertStmt->execute([
            $user['id'],
            $tokenHash
        ]);


        $db->commit();


        return send_verification_email(
            $user,
            $token
        );


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw $exception;
    }
}


/* =========================================================
   INITIAL STATE
   ========================================================= */

$errors =
    [];

$success =
    '';


$username =
    (string) (
        $user['username']
        ?? ''
    );


$displayName =
    (string) (
        $user['display_name']
        ?? ''
    );


$email =
    (string) (
        $user['email']
        ?? ''
    );


$timezone =
    llama_user_timezone(
        $user
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


$bio =
    (string) (
        $communityProfile[
            'bio'
        ]
        ?? ''
    );


$location =
    (string) (
        $communityProfile[
            'location'
        ]
        ?? ''
    );


$squad =
    (string) (
        $communityProfile[
            'squad'
        ]
        ?? ''
    );


$websiteUrl =
    (string) (
        $communityProfile[
            'website_url'
        ]
        ?? ''
    );


$instagramUrl =
    (string) (
        $communityProfile[
            'instagram_url'
        ]
        ?? ''
    );


$facebookUrl =
    (string) (
        $communityProfile[
            'facebook_url'
        ]
        ?? ''
    );


$blueskyUrl =
    (string) (
        $communityProfile[
            'bluesky_url'
        ]
        ?? ''
    );


$youtubeUrl =
    (string) (
        $communityProfile[
            'youtube_url'
        ]
        ?? ''
    );


$tiktokUrl =
    (string) (
        $communityProfile[
            'tiktok_url'
        ]
        ?? ''
    );


$otherSocialUrl =
    (string) (
        $communityProfile[
            'other_social_url'
        ]
        ?? ''
    );


$campingStyle =
    (string) (
        $communityProfile[
            'camping_style'
        ]
        ?? ''
    );


$favoritePlaces =
    (string) (
        $communityProfile[
            'favorite_places'
        ]
        ?? ''
    );


$favoriteCampingMusic =
    (string) (
        $communityProfile[
            'favorite_camping_music'
        ]
        ?? ''
    );


/* =========================================================
   SAVE PROFILE
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    $username =
        strtolower(
            trim(
                (string) (
                    $_POST[
                        'username'
                    ]
                    ?? ''
                )
            )
        );


    $displayName =
        trim(
            (string) (
                $_POST[
                    'display_name'
                ]
                ?? ''
            )
        );


    $email =
        strtolower(
            trim(
                (string) (
                    $_POST[
                        'email'
                    ]
                    ?? ''
                )
            )
        );


    $timezone =
        trim(
            (string) (
                $_POST[
                    'timezone'
                ]
                ?? llama_default_timezone()
            )
        );


    $isPublic =
        isset(
            $_POST[
                'is_public'
            ]
        );


    $bio =
        trim(
            (string) (
                $_POST[
                    'bio'
                ]
                ?? ''
            )
        );


    $location =
        trim(
            (string) (
                $_POST[
                    'location'
                ]
                ?? ''
            )
        );


    $squad =
        trim(
            (string) (
                $_POST[
                    'squad'
                ]
                ?? ''
            )
        );


    $websiteUrl =
        trim(
            (string) (
                $_POST[
                    'website_url'
                ]
                ?? ''
            )
        );


    $instagramUrl =
        trim(
            (string) (
                $_POST[
                    'instagram_url'
                ]
                ?? ''
            )
        );


    $facebookUrl =
        trim(
            (string) (
                $_POST[
                    'facebook_url'
                ]
                ?? ''
            )
        );


    $blueskyUrl =
        trim(
            (string) (
                $_POST[
                    'bluesky_url'
                ]
                ?? ''
            )
        );


    $youtubeUrl =
        trim(
            (string) (
                $_POST[
                    'youtube_url'
                ]
                ?? ''
            )
        );


    $tiktokUrl =
        trim(
            (string) (
                $_POST[
                    'tiktok_url'
                ]
                ?? ''
            )
        );


    $otherSocialUrl =
        trim(
            (string) (
                $_POST[
                    'other_social_url'
                ]
                ?? ''
            )
        );


    $campingStyle =
        trim(
            (string) (
                $_POST[
                    'camping_style'
                ]
                ?? ''
            )
        );


    $favoritePlaces =
        trim(
            (string) (
                $_POST[
                    'favorite_places'
                ]
                ?? ''
            )
        );


    $favoriteCampingMusic =
        trim(
            (string) (
                $_POST[
                    'favorite_camping_music'
                ]
                ?? ''
            )
        );


    /* =====================================================
       VALIDATION
       ===================================================== */

    $usernamePolicy =
        username_policy_check(
            $username
        );


    if (
        !$usernamePolicy[
            'allowed'
        ]
    ) {

        $errors[] =
            $usernamePolicy[
                'reason'
            ];
    }


    if (
        $displayName === ''
        ||
        mb_strlen(
            $displayName
        ) < 2
    ) {

        $errors[] =
            'Enter a display name.';
    }


    if (
        mb_strlen(
            $displayName
        ) > 100
    ) {

        $errors[] =
            'Display name must be 100 characters or fewer.';
    }


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errors[] =
            'Enter a valid email address.';
    }


    if (
        !llama_timezone_is_valid(
            $timezone
        )
    ) {

        $errors[] =
            'Choose a valid time zone.';
    }


    if (
        mb_strlen(
            $bio
        ) > 1000
    ) {

        $errors[] =
            'Your bio must be 1,000 characters or fewer.';
    }


    if (
        mb_strlen(
            $location
        ) > 150
    ) {

        $errors[] =
            'Location must be 150 characters or fewer.';
    }


    if (
        mb_strlen(
            $squad
        ) > 150
    ) {

        $errors[] =
            'Squad or club name must be 150 characters or fewer.';
    }


    $urlFields = [

        'Website' =>
            $websiteUrl,

        'Instagram' =>
            $instagramUrl,

        'Facebook' =>
            $facebookUrl,

        'Bluesky' =>
            $blueskyUrl,

        'YouTube' =>
            $youtubeUrl,

        'TikTok' =>
            $tiktokUrl,

        'Other social link' =>
            $otherSocialUrl,

    ];


    foreach (
        $urlFields
        as $label =>
        $url
    ) {

        if (
            $url !== ''
            &&
            !filter_var(
                $url,
                FILTER_VALIDATE_URL
            )
        ) {

            $errors[] =
                $label
                .
                ' must be a complete URL.';
        }
    }


    /* =====================================================
       DUPLICATE USERNAME
       ===================================================== */

    if (
        !$errors
    ) {

        $stmt =
            $db->prepare(
                '
                SELECT id

                FROM users

                WHERE LOWER(username) = ?
                  AND id != ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $username,
            $userId
        ]);


        if (
            $stmt->fetch()
        ) {

            $errors[] =
                'That username is already taken.';
        }
    }


    /* =====================================================
       DUPLICATE EMAIL
       ===================================================== */

    if (
        !$errors
    ) {

        $stmt =
            $db->prepare(
                '
                SELECT id

                FROM users

                WHERE LOWER(email) = ?
                  AND id != ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $email,
            $userId
        ]);


        if (
            $stmt->fetch()
        ) {

            $errors[] =
                'An account already exists with that email address.';
        }
    }


    /* =====================================================
       SAVE
       ===================================================== */

    if (
        !$errors
    ) {

        $oldEmail =
            strtolower(
                (string) (
                    $user[
                        'email'
                    ]
                    ?? ''
                )
            );


        $emailChanged =
            $email !==
            $oldEmail;


        try {

            $db->beginTransaction();


            if (
                $emailChanged
            ) {

                $stmt =
                    $db->prepare(
                        '
                        UPDATE users

                        SET
                            username = ?,
                            display_name = ?,
                            email = ?,
                            timezone = ?,
                            email_verified_at = NULL

                        WHERE id = ?
                        '
                    );


                $stmt->execute([
                    $username,
                    $displayName,
                    $email,
                    $timezone,
                    $userId
                ]);


            } else {

                $stmt =
                    $db->prepare(
                        '
                        UPDATE users

                        SET
                            username = ?,
                            display_name = ?,
                            timezone = ?

                        WHERE id = ?
                        '
                    );


                $stmt->execute([
                    $username,
                    $displayName,
                    $timezone,
                    $userId
                ]);
            }


            $profileStmt =
                $db->prepare(
                    '
                    UPDATE community_profiles

                    SET
                        is_public = ?,
                        bio = ?,
                        location = ?,
                        squad = ?,
                        website_url = ?,
                        instagram_url = ?,
                        facebook_url = ?,
                        bluesky_url = ?,
                        youtube_url = ?,
                        tiktok_url = ?,
                        other_social_url = ?,
                        camping_style = ?,
                        favorite_places = ?,
                        favorite_camping_music = ?

                    WHERE user_id = ?
                    '
                );


            $profileStmt->execute([

                $isPublic
                    ? 1
                    : 0,

                $bio !== ''
                    ? $bio
                    : null,

                $location !== ''
                    ? $location
                    : null,

                $squad !== ''
                    ? $squad
                    : null,

                $websiteUrl !== ''
                    ? $websiteUrl
                    : null,

                $instagramUrl !== ''
                    ? $instagramUrl
                    : null,

                $facebookUrl !== ''
                    ? $facebookUrl
                    : null,

                $blueskyUrl !== ''
                    ? $blueskyUrl
                    : null,

                $youtubeUrl !== ''
                    ? $youtubeUrl
                    : null,

                $tiktokUrl !== ''
                    ? $tiktokUrl
                    : null,

                $otherSocialUrl !== ''
                    ? $otherSocialUrl
                    : null,

                $campingStyle !== ''
                    ? $campingStyle
                    : null,

                $favoritePlaces !== ''
                    ? $favoritePlaces
                    : null,

                $favoriteCampingMusic !== ''
                    ? $favoriteCampingMusic
                    : null,

                $userId

            ]);


            $db->commit();


            $user =
                current_user();


            if (
                $emailChanged
            ) {

                $emailSent =
                    create_email_verification(
                        $db,
                        $user
                    );


                $success =
                    $emailSent
                        ? 'Profile updated. We sent a verification link to your new email address.'
                        : 'Profile updated, but the verification email could not be sent.';

            } else {

                $success =
                    'Your profile has been updated.';
            }


        } catch (
            Throwable $exception
        ) {

            if (
                $db->inTransaction()
            ) {

                $db->rollBack();
            }


            error_log(
                'Llama Scout profile update error: '
                .
                $exception
                    ->getMessage()
            );


            $errors[] =
                'Your profile could not be updated. Please try again.';
        }
    }
}


/* =========================================================
   REFRESH STATE
   ========================================================= */

$user =
    current_user();


$username =
    (string) (
        $user[
            'username'
        ]
        ?? $username
    );


$displayName =
    (string) (
        $user[
            'display_name'
        ]
        ?? $displayName
    );


$email =
    (string) (
        $user[
            'email'
        ]
        ?? $email
    );


$timezone =
    llama_user_timezone(
        $user
    );


$isVerified =
    !empty(
        $user[
            'email_verified_at'
        ]
    );


$profileImages =
    llama_community_profile_images(
        $db,
        $userId
    );


$primaryProfileImage =
    llama_primary_profile_image(
        $db,
        $userId
    );


$userBadges =
    llama_user_badges(
        $db,
        $userId
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
  Profile | Llama Scout
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


<main class="account-page">


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


  <section class="account-card">


    <h1>
      Community Profile
    </h1>


    <p class="account-intro">

      Your profile is your identity around
      the Llama Scout community.

    </p>


    <?php if ($success): ?>

      <div
        class="
          account-status
          account-status--success
        "
      >

        <?= e($success) ?>

      </div>

    <?php endif; ?>


    <?php if ($errors): ?>

      <div
        class="
          account-status
          account-status--error
        "
      >

        <ul>

          <?php foreach (
              $errors
              as $error
          ): ?>

            <li>
              <?= e($error) ?>
            </li>

          <?php endforeach; ?>

        </ul>

      </div>

    <?php endif; ?>


    <!-- ===================================================
         PROFILE PHOTO
         =================================================== -->

    <section class="community-profile-editor-section">

      <h2>
        Profile Photos
      </h2>


      <div class="community-profile-avatar-preview">

        <img
          src="<?= e(
              $primaryProfileImage
          ) ?>"
          alt="Current profile photo"
          data-profile-avatar
        >

      </div>


      <p class="account-field-note">

        Upload up to five images.
        Choose any one as your profile photo.
        If your selected photo is deleted,
        the default llama takes over.

      </p>


      <form
        id="profile-image-upload-form"
        enctype="multipart/form-data"
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $profileImageCsrf
          ) ?>"
        >


        <input
          id="profile-images"
          name="photos[]"
          type="file"
          accept="image/*"
          multiple
          <?= count(
              $profileImages
          ) >= 5
              ? 'disabled'
              : ''
          ?>
        >


        <button
          type="submit"
          class="account-submit"
          <?= count(
              $profileImages
          ) >= 5
              ? 'disabled'
              : ''
          ?>
        >
          Upload Photos
        </button>

      </form>


      <div
        class="community-profile-image-grid"
        data-profile-image-grid
      >

        <?php foreach (
            $profileImages
            as $image
        ): ?>

          <?php

          $isPrimary =
              (int) (
                  $communityProfile[
                      'primary_image_id'
                  ]
                  ?? 0
              )
              ===
              (int)
              $image[
                  'id'
              ];

          ?>


          <article
            class="community-profile-image"
            data-profile-image-id="<?= (int)
                $image[
                    'id'
                ]
            ?>"
          >

            <img
              src="<?= e(
                  $image[
                      'image_src'
                  ]
              ) ?>"
              alt="<?= e(
                  $image[
                      'alt_text'
                  ]
                  ?? 'Profile image'
              ) ?>"
            >


            <?php if ($isPrimary): ?>

              <strong>
                Current Profile Photo
              </strong>

            <?php else: ?>

              <button
                type="button"
                data-set-primary-profile-image
                data-image-id="<?= (int)
                    $image[
                        'id'
                    ]
                ?>"
              >
                Make Profile Photo
              </button>

            <?php endif; ?>


            <button
              type="button"
              data-delete-profile-image
              data-image-id="<?= (int)
                  $image[
                      'id'
                  ]
              ?>"
            >
              Delete
            </button>

          </article>

        <?php endforeach; ?>

      </div>


      <div
        class="account-status"
        data-profile-image-status
        hidden
      ></div>

    </section>


    <!-- ===================================================
         BADGES
         =================================================== -->

    <section class="community-profile-editor-section">

      <h2>
        Badges
      </h2>


      <?php if ($userBadges): ?>

        <div class="community-profile-badge-grid">

          <?php foreach (
              $userBadges
              as $badge
          ): ?>

            <div class="community-profile-badge">

              <i
                class="fa-solid <?= e(
                    $badge[
                        'icon'
                    ]
                    ?? 'fa-award'
                ) ?>"
                aria-hidden="true"
              ></i>

              <strong>
                <?= e(
                    $badge[
                        'name'
                    ]
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
                  <?= e(
                      $badge[
                          'description'
                      ]
                  ) ?>
                </span>

              <?php endif; ?>

            </div>

          <?php endforeach; ?>

        </div>


      <?php else: ?>

        <p class="account-field-note">

          No badges yet.
          Your collection will grow as you
          contribute, Scout places, and complete
          recognized outdoor training.

        </p>

      <?php endif; ?>

    </section>


    <!-- ===================================================
         PROFILE FORM
         =================================================== -->

    <form method="post">


      <h2>
        Account Identity
      </h2>


      <div class="account-field">

        <label for="username">
          Username
        </label>

        <input
          id="username"
          name="username"
          type="text"
          minlength="4"
          maxlength="16"
          autocomplete="username"
          autocapitalize="none"
          spellcheck="false"
          value="<?= e(
              $username
          ) ?>"
          required
        >

        <p class="account-field-note">

          Your profile address will be:

          <strong>
            llamascout.com/profile/<?= e(
                $username
            ) ?>
          </strong>

        </p>

      </div>


      <div class="account-field">

        <label for="display_name">
          Display name
        </label>

        <input
          id="display_name"
          name="display_name"
          type="text"
          maxlength="100"
          autocomplete="name"
          value="<?= e(
              $displayName
          ) ?>"
          required
        >

      </div>


      <!-- =================================================
           COMMUNITY INFO
           ================================================= -->

      <h2>
        About You
      </h2>


      <div class="account-field">

        <label for="bio">
          Bio
        </label>

        <textarea
          id="bio"
          name="bio"
          maxlength="1000"
          rows="6"
          placeholder="Tell the community a little about yourself..."
        ><?= e($bio) ?></textarea>

      </div>


      <div class="account-field">

        <label for="location">
          General location
        </label>

        <input
          id="location"
          name="location"
          type="text"
          maxlength="150"
          placeholder="Durango, Colorado"
          value="<?= e(
              $location
          ) ?>"
        >

        <p class="account-field-note">
          Keep this general. Do not use a street address.
        </p>

      </div>


      <div class="account-field">

        <label for="squad">
          Squad / Club
        </label>

        <input
          id="squad"
          name="squad"
          type="text"
          maxlength="150"
          placeholder="Your camping, trail, or outdoor group"
          value="<?= e(
              $squad
          ) ?>"
        >

      </div>


      <div class="account-field">

        <label for="camping_style">
          Camping style
        </label>

        <input
          id="camping_style"
          name="camping_style"
          type="text"
          maxlength="255"
          placeholder="Overlanding, tent camping, vanlife..."
          value="<?= e(
              $campingStyle
          ) ?>"
        >

      </div>


      <div class="account-field">

        <label for="favorite_places">
          Favorite kind of place
        </label>

        <input
          id="favorite_places"
          name="favorite_places"
          type="text"
          maxlength="255"
          placeholder="High desert, alpine forest, riverside..."
          value="<?= e(
              $favoritePlaces
          ) ?>"
        >

      </div>


      <div class="account-field">

        <label for="favorite_camping_music">
          Camping soundtrack
        </label>

        <input
          id="favorite_camping_music"
          name="favorite_camping_music"
          type="text"
          maxlength="255"
          placeholder="What belongs on the camp playlist?"
          value="<?= e(
              $favoriteCampingMusic
          ) ?>"
        >

      </div>


      <!-- =================================================
           LINKS
           ================================================= -->

      <h2>
        Around the Internet
      </h2>


      <?php

      $socialFields = [

          'website_url' => [
              'Website',
              $websiteUrl
          ],

          'instagram_url' => [
              'Instagram',
              $instagramUrl
          ],

          'facebook_url' => [
              'Facebook',
              $facebookUrl
          ],

          'bluesky_url' => [
              'Bluesky',
              $blueskyUrl
          ],

          'youtube_url' => [
              'YouTube',
              $youtubeUrl
          ],

          'tiktok_url' => [
              'TikTok',
              $tiktokUrl
          ],

          'other_social_url' => [
              'Other link',
              $otherSocialUrl
          ],

      ];

      ?>


      <?php foreach (
          $socialFields
          as $fieldName =>
          $fieldData
      ): ?>

        <div class="account-field">

          <label for="<?= e(
              $fieldName
          ) ?>">

            <?= e(
                $fieldData[0]
            ) ?>

          </label>

          <input
            id="<?= e(
                $fieldName
            ) ?>"
            name="<?= e(
                $fieldName
            ) ?>"
            type="url"
            maxlength="500"
            placeholder="https://"
            value="<?= e(
                $fieldData[1]
            ) ?>"
          >

        </div>

      <?php endforeach; ?>


      <!-- =================================================
           VISIBILITY
           ================================================= -->

      <h2>
        Profile Visibility
      </h2>


      <label class="community-profile-public-toggle">

        <input
          type="checkbox"
          name="is_public"
          value="1"
          <?= $isPublic
              ? 'checked'
              : ''
          ?>
        >

        <span>

          <strong>
            Public Profile
          </strong>

          <small>

            When off, only signed-in Llama Scout
            community members can view your profile.

            When on, anyone with the link can see it
            and search engines may index it.

          </small>

        </span>

      </label>


      <!-- =================================================
           PRIVATE ACCOUNT SETTINGS
           ================================================= -->

      <h2>
        Private Account Settings
      </h2>


      <div class="account-field">

        <label for="email">
          Email address
        </label>

        <input
          id="email"
          name="email"
          type="email"
          maxlength="255"
          autocomplete="email"
          value="<?= e(
              $email
          ) ?>"
          required
        >

        <p class="account-field-note">

          Your email is never displayed
          on your Community Profile.

        </p>

      </div>


      <div class="account-field">

        <label for="timezone">
          Time zone
        </label>

        <select
          id="timezone"
          name="timezone"
          required
        >

          <?php foreach (
              llama_timezones()
              as $zone =>
              $label
          ): ?>

            <option
              value="<?= e(
                  $zone
              ) ?>"
              <?= $timezone === $zone
                  ? 'selected'
                  : ''
              ?>
            >

              <?= e(
                  $label
              ) ?>

            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <div class="account-email-state">

        <strong>

          Email status:

          <?= $isVerified
              ? 'Verified'
              : 'Verification required'
          ?>

        </strong>


        <?php if (
            !$isVerified
        ): ?>

          Check your inbox or

          <a href="resend-verification.php">
            resend verification
          </a>.

        <?php endif; ?>

      </div>


      <button
        type="submit"
        class="account-submit"
      >
        Save Community Profile
      </button>


    </form>


  </section>


</main>


<script>

window.LLAMA_PROFILE_IMAGES = {
    csrfToken:
        <?= json_encode(
            $profileImageCsrf
        ) ?>,

    defaultImage:
        <?= json_encode(
            LLAMA_DEFAULT_PROFILE_IMAGE
        ) ?>
};

</script>


<script
  src="https://llamascout.com/js/header.js"
></script>

<script
  src="https://llamascout.com/js/community-profile-editor.js"
></script>


</body>

</html>
