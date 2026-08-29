<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SELF-SERVICE ACCOUNT DELETION
   account/delete-account.php

   Account deletion is implemented as anonymization so
   published contribution provenance can remain intact.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';

require_once
    dirname(__DIR__)
    . '/app/memberships.php';


require_login();

start_llama_session();


$db =
    db();


$user =
    current_user();


if (!$user) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );
}


$userId =
    (int)
    $user['id'];


/* =========================================================
   HELPERS
   ========================================================= */


function delete_account_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function delete_account_table_exists(
    PDO $db,
    string $table
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM information_schema.tables

            WHERE table_schema = DATABASE()
              AND table_name = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $table
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


function delete_account_column_exists(
    PDO $db,
    string $table,
    string $column
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM information_schema.columns

            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $table,
        $column,
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


function delete_account_delete_user_rows(
    PDO $db,
    string $table,
    int $userId
): int {

    if (
        !preg_match(
            '/^[a-zA-Z0-9_]+$/',
            $table
        )
    ) {

        throw new RuntimeException(
            'Unsafe database table identifier.'
        );
    }


    if (
        !delete_account_table_exists(
            $db,
            $table
        )
        ||
        !delete_account_column_exists(
            $db,
            $table,
            'user_id'
        )
    ) {

        return 0;
    }


    $stmt =
        $db->prepare(
            'DELETE FROM `'
            .
            $table
            .
            '` WHERE user_id = ?'
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->rowCount();
}


function delete_account_roles(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT r.slug

            FROM roles r

            INNER JOIN user_roles ur
              ON ur.role_id = r.id

            WHERE ur.user_id = ?
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        array_values(
            array_filter(
                array_map(
                    'strval',
                    array_column(
                        $stmt->fetchAll(
                            PDO::FETCH_ASSOC
                        ),
                        'slug'
                    )
                )
            )
        );
}


function delete_account_assign_member_role(
    PDO $db,
    int $userId
): void {

    delete_account_delete_user_rows(
        $db,
        'user_roles',
        $userId
    );


    $stmt =
        $db->prepare(
            '
            SELECT id

            FROM roles

            WHERE slug = \'member\'

            LIMIT 1
            '
        );


    $stmt->execute();


    $roleId =
        (int)
        $stmt->fetchColumn();


    if (
        $roleId < 1
    ) {

        throw new RuntimeException(
            'The Member role is missing.'
        );
    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO user_roles
            (
                user_id,
                role_id
            )
            VALUES
            (
                ?,
                ?
            )
            '
        );


    $stmt->execute([
        $userId,
        $roleId,
    ]);
}


function delete_account_anonymous_username(
    int $userId
): string {

    return
        'user_'
        .
        str_pad(
            (string) $userId,
            4,
            '0',
            STR_PAD_LEFT
        );
}


function delete_account_anonymous_email(
    int $userId
): string {

    return
        'deleted+'
        .
        str_pad(
            (string) $userId,
            4,
            '0',
            STR_PAD_LEFT
        )
        .
        '@llamascout.invalid';
}


function delete_account_random_password_hash(): string {

    $hash =
        password_hash(
            bin2hex(
                random_bytes(
                    64
                )
            ),
            PASSWORD_DEFAULT
        );


    if (
        !is_string(
            $hash
        )
        ||
        $hash === ''
    ) {

        throw new RuntimeException(
            'Unable to replace account credentials.'
        );
    }


    return
        $hash;
}


function delete_account_remove_profile_files(
    PDO $db,
    int $userId
): void {

    if (
        !delete_account_table_exists(
            $db,
            'community_profile_images'
        )
        ||
        !delete_account_column_exists(
            $db,
            'community_profile_images',
            'user_id'
        )
        ||
        !delete_account_column_exists(
            $db,
            'community_profile_images',
            'image_src'
        )
    ) {

        return;
    }


    $stmt =
        $db->prepare(
            '
            SELECT image_src

            FROM community_profile_images

            WHERE user_id = ?
            '
        );


    $stmt->execute([
        $userId
    ]);


    $documentRoot =
        realpath(
            dirname(__DIR__)
        );


    foreach (
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        )
        as
        $imageSrc
    ) {

        if (
            !is_string(
                $imageSrc
            )
            ||
            trim(
                $imageSrc
            ) === ''
            ||
            !is_string(
                $documentRoot
            )
        ) {

            continue;
        }


        $path =
            parse_url(
                $imageSrc,
                PHP_URL_PATH
            );


        if (
            !is_string(
                $path
            )
            ||
            !str_starts_with(
                $path,
                '/uploads/'
            )
        ) {

            continue;
        }


        $candidate =
            dirname(__DIR__)
            .
            '/'
            .
            ltrim(
                $path,
                '/'
            );


        $realCandidate =
            realpath(
                $candidate
            );


        if (
            $realCandidate !== false
            &&
            str_starts_with(
                $realCandidate,
                $documentRoot
                .
                DIRECTORY_SEPARATOR
                .
                'uploads'
                .
                DIRECTORY_SEPARATOR
            )
            &&
            is_file(
                $realCandidate
            )
        ) {

            @unlink(
                $realCandidate
            );
        }
    }
}


function delete_account_reset_profile(
    PDO $db,
    int $userId
): void {

    delete_account_remove_profile_files(
        $db,
        $userId
    );


    delete_account_delete_user_rows(
        $db,
        'community_profile_images',
        $userId
    );


    if (
        !delete_account_table_exists(
            $db,
            'community_profiles'
        )
        ||
        !delete_account_column_exists(
            $db,
            'community_profiles',
            'user_id'
        )
    ) {

        return;
    }


    $values = [
        'is_public' => 0,
        'bio' => null,
        'location' => null,
        'squad' => null,
        'website_url' => null,
        'instagram_url' => null,
        'facebook_url' => null,
        'bluesky_url' => null,
        'youtube_url' => null,
        'tiktok_url' => null,
        'other_social_url' => null,
        'camping_style' => null,
        'favorite_places' => null,
        'favorite_camping_music' => null,
        'primary_image_id' => null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            delete_account_column_exists(
                $db,
                'community_profiles',
                $column
            )
        ) {

            $assignments[] =
                '`'
                .
                $column
                .
                '` = ?';

            $params[] =
                $value;
        }
    }


    if (!$assignments) {

        return;
    }


    $params[] =
        $userId;


    $stmt =
        $db->prepare(
            '
            UPDATE community_profiles

            SET
                '
                .
                implode(
                    ",
                ",
                    $assignments
                )
                .
            '

            WHERE user_id = ?
            '
        );


    $stmt->execute(
        $params
    );
}


function delete_account_delete_unpublished_contributions(
    PDO $db,
    int $userId
): void {

    if (
        delete_account_table_exists(
            $db,
            'place_submissions'
        )
        &&
        delete_account_column_exists(
            $db,
            'place_submissions',
            'user_id'
        )
        &&
        delete_account_column_exists(
            $db,
            'place_submissions',
            'place_id'
        )
    ) {

        $stmt =
            $db->prepare(
                '
                DELETE FROM place_submissions

                WHERE user_id = ?
                  AND place_id IS NULL
                '
            );


        $stmt->execute([
            $userId
        ]);
    }


    if (
        delete_account_table_exists(
            $db,
            'place_update_submissions'
        )
        &&
        delete_account_column_exists(
            $db,
            'place_update_submissions',
            'user_id'
        )
        &&
        delete_account_column_exists(
            $db,
            'place_update_submissions',
            'status'
        )
    ) {

        $stmt =
            $db->prepare(
                '
                DELETE FROM place_update_submissions

                WHERE user_id = ?
                  AND (
                      status IS NULL
                      OR status <> \'approved\'
                  )
                '
            );


        $stmt->execute([
            $userId
        ]);
    }
}


function delete_account_reset_membership(
    PDO $db,
    int $userId
): void {

    delete_account_delete_user_rows(
        $db,
        'membership_grants',
        $userId
    );


    $values = [
        'stripe_customer_id' => null,
        'stripe_subscription_id' => null,
        'stripe_cancel_at_period_end' => 0,
        'membership_status' => 'none',
        'membership_interval' => null,
        'membership_started_at' => null,
        'membership_ends_at' => null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            delete_account_column_exists(
                $db,
                'users',
                $column
            )
        ) {

            $assignments[] =
                '`'
                .
                $column
                .
                '` = ?';

            $params[] =
                $value;
        }
    }


    if (!$assignments) {

        return;
    }


    $params[] =
        $userId;


    $stmt =
        $db->prepare(
            '
            UPDATE users

            SET
                '
                .
                implode(
                    ",
                ",
                    $assignments
                )
                .
            '

            WHERE id = ?
            '
        );


    $stmt->execute(
        $params
    );
}


function delete_account_cancel_stripe_subscription(
    ?string $subscriptionId,
    int $userId
): void {

    $subscriptionId =
        trim(
            (string) $subscriptionId
        );


    if (
        $subscriptionId === ''
    ) {

        return;
    }


    try {

        $stripe =
            llama_stripe_client();


        $stripe
            ->subscriptions
            ->cancel(
                $subscriptionId,
                [
                    'cancellation_details' => [
                        'comment' =>
                            'Canceled immediately because the member permanently deleted their Llama Scout account.',
                    ],
                ]
            );


    } catch (
        Throwable $exception
    ) {

        $message =
            strtolower(
                $exception
                    ->getMessage()
            );


        if (
            str_contains(
                $message,
                'no such subscription'
            )
            ||
            str_contains(
                $message,
                'resource_missing'
            )
            ||
            str_contains(
                $message,
                'already canceled'
            )
    ) {

            return;
        }


        error_log(
            'Llama Scout account deletion could not cancel Stripe subscription for user #'
            .
            $userId
            .
            ': '
            .
            $exception->getMessage()
        );


        throw new RuntimeException(
            'We could not cancel your paid membership with our payment processor, so your account was not deleted. No account data was changed. Please try again or contact support.'
        );
    }
}


function delete_account_anonymize_identity(
    PDO $db,
    int $userId
): string {

    $anonymousUsername =
        delete_account_anonymous_username(
            $userId
        );


    $values = [
        'email' =>
            delete_account_anonymous_email(
                $userId
            ),

        'username' =>
            $anonymousUsername,

        'display_name' =>
            'Deleted User',

        'password_hash' =>
            delete_account_random_password_hash(),

        'timezone' =>
            'America/Denver',

        'status' =>
            'disabled',

        'email_verified_at' =>
            null,

        'last_login_at' =>
            null,

        'dormancy_notice_sent_at' =>
            null,
    ];


    $assignments = [];

    $params = [];


    foreach (
        $values
        as
        $column => $value
    ) {

        if (
            delete_account_column_exists(
                $db,
                'users',
                $column
            )
        ) {

            $assignments[] =
                '`'
                .
                $column
                .
                '` = ?';

            $params[] =
                $value;
        }
    }


    $params[] =
        $userId;


    $stmt =
        $db->prepare(
            '
            UPDATE users

            SET
                '
                .
                implode(
                    ",
                ",
                    $assignments
                )
                .
            '

            WHERE id = ?
            '
        );


    $stmt->execute(
        $params
    );


    return
        $anonymousUsername;
}


/* =========================================================
   LOAD AUTHENTICATION DATA
   ========================================================= */


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            username,
            display_name,
            email,
            password_hash,
            stripe_customer_id,
            stripe_subscription_id,
            membership_status

        FROM users

        WHERE id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$account =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (!$account) {

    http_response_code(
        404
    );

    exit(
        'Account not found.'
    );
}


$roles =
    delete_account_roles(
        $db,
        $userId
    );


$privilegedAccount =
    in_array(
        'owner',
        $roles,
        true
    )
    ||
    in_array(
        'admin',
        $roles,
        true
    );


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'delete_account_csrf'
        ]
    )
) {

    $_SESSION[
        'delete_account_csrf'
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
        'delete_account_csrf'
    ];


/* =========================================================
   FORM STATE
   ========================================================= */


$error =
    '';

$completed =
    false;

$anonymousUsername =
    '';


/* =========================================================
   DELETE / ANONYMIZE
   ========================================================= */


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedCsrf =
        $_POST['csrf_token']
        ?? '';


    $typedUsername =
        trim(
            (string) (
                $_POST['username_confirm']
                ?? ''
            )
        );


    $password =
        (string) (
            $_POST['password_confirm']
            ?? ''
        );


    $requiredConfirmations = [
        'confirm_permanent',
        'confirm_contributions',
        'confirm_forfeit_status',
        'confirm_membership',
        'confirm_retention',
        'confirm_request',
    ];


    try {

        if (
            $privilegedAccount
        ) {

            throw new RuntimeException(
                'Owner and Admin accounts cannot be deleted through self-service. Privileged access must be transferred or removed first.'
            );
        }


        if (
            !is_string(
                $submittedCsrf
            )
            ||
            !hash_equals(
                $csrfToken,
                $submittedCsrf
            )
        ) {

            throw new RuntimeException(
                'Your session could not be verified. Reload the page and try again.'
            );
        }


        if (
            $typedUsername !==
            (string) $account['username']
        ) {

            throw new RuntimeException(
                'The username you entered does not exactly match this account.'
            );
        }


        if (
            $password === ''
            ||
            !password_verify(
                $password,
                (string) $account['password_hash']
            )
        ) {

            throw new RuntimeException(
                'Your password is incorrect.'
            );
        }


        foreach (
            $requiredConfirmations
            as
            $confirmation
        ) {

            if (
                !isset(
                    $_POST[
                        $confirmation
                    ]
                )
            ) {

                throw new RuntimeException(
                    'You must acknowledge every account-deletion statement before continuing.'
                );
            }
        }


        /*
         * Stripe is handled first. If immediate cancellation
         * fails, no local account information is changed.
         */

        delete_account_cancel_stripe_subscription(
            $account[
                'stripe_subscription_id'
            ]
            ?? null,
            $userId
        );


        $db->beginTransaction();


        /*
         * Remove authentication, profile, saved, badge,
         * membership, and Scout state.
         */

        delete_account_delete_user_rows(
            $db,
            'user_remember_tokens',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'email_verifications',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'password_resets',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'user_saved_places',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'saved_places',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'user_badges',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'scout_profiles',
            $userId
        );


        delete_account_delete_user_rows(
            $db,
            'scout_applications',
            $userId
        );


        delete_account_reset_profile(
            $db,
            $userId
        );


        delete_account_delete_unpublished_contributions(
            $db,
            $userId
        );


        delete_account_assign_member_role(
            $db,
            $userId
        );


        delete_account_reset_membership(
            $db,
            $userId
        );


        $anonymousUsername =
            delete_account_anonymize_identity(
                $db,
                $userId
            );


        /*
         * Keep the audit deliberately free of the former
         * username, display name, email, or password data.
         */

        llama_membership_audit(
            $db,
            $userId,
            'self_account_anonymized',
            'user',
            $userId,
            [
                'replacement_username' =>
                    $anonymousUsername,

                'initiated_by' =>
                    'member_self_service',
            ]
        );


        $db->commit();


        /*
         * End this browser session immediately. The local
         * variables above remain available to render the
         * final confirmation in this request.
         */

        logout_user();


        $completed =
            true;


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        error_log(
            'Llama Scout self-service account deletion error for user #'
            .
            $userId
            .
            ': '
            .
            $exception->getMessage()
        );


        $error =
            $exception->getMessage();
    }
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

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Delete Account | Llama Scout
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
    href="https://llamascout.com/css/account-delete.css"
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


<main class="delete-account-shell">


  <?php if (
      $completed
  ): ?>


    <section
      class="
        delete-account-card
        delete-account-card--complete
      "
    >

      <div class="delete-account-icon">
        <i
          class="fa-solid fa-check"
          aria-hidden="true"
        ></i>
      </div>

      <p class="delete-account-eyebrow">
        Account Deleted
      </p>

      <h1>
        You've left the herd.
      </h1>

      <p>
        Your Llama Scout account has been permanently
        anonymized and you have been signed out.
      </p>

      <p>
        Historical Places and approved contributions may
        remain as part of Llama Scout, but they now belong
        to
        <strong>
          <?= delete_account_e(
              $anonymousUsername
          ) ?>
        </strong>
        / Deleted User rather than your former account
        identity.
      </p>

      <div class="delete-account-actions">

        <a
          href="https://llamascout.com"
          class="delete-account-button"
        >
          Return to Llama Scout
        </a>

      </div>

    </section>


  <?php else: ?>


    <header class="delete-account-header">

      <a
        href="index.php"
        class="delete-account-back"
      >
        <i
          class="fa-solid fa-arrow-left"
          aria-hidden="true"
        ></i>
        Back to My Account
      </a>

      <p class="delete-account-eyebrow">
        Account Settings
      </p>

      <h1>
        Delete My Account
      </h1>

      <p>
        This action is immediate and permanent. Llama Scout
        preserves historical contribution provenance by
        anonymizing your account instead of erasing published
        community records.
      </p>

    </header>


    <?php if (
        $error !== ''
    ): ?>

      <div
        class="
          delete-account-notice
          delete-account-notice--error
        "
      >
        <?= delete_account_e(
            $error
        ) ?>
      </div>

    <?php endif; ?>


    <?php if (
        $privilegedAccount
    ): ?>

      <div
        class="
          delete-account-notice
          delete-account-notice--warning
        "
      >
        This account currently has Owner or Admin privileges.
        It cannot be deleted through self-service until those
        privileges have been transferred or removed.
      </div>

    <?php endif; ?>


    <section class="delete-account-card">

      <div class="delete-account-legal">

        <span class="delete-account-legal-icon">
          <i
            class="fa-solid fa-scale-balanced"
            aria-hidden="true"
          ></i>
        </span>

        <div>

          <strong>
            The llamas' legal team makes us say this.
          </strong>

          <p>
            Deleting an account affects more than your login.
            Read every item below before confirming. There is
            no undo button hiding behind the hay bale.
          </p>

        </div>

      </div>


      <h2>
        What deletion means
      </h2>


      <ul class="delete-account-list">

        <li>
          Your username, display name, email address, profile,
          profile photos, saved Places, authentication data,
          and any other personal account information are removed,
          replaced, or anonymized where appropriate.
        </li>

        <li>
          Published Places and approved contributions are not
          deleted solely because you close your account. They
          remain part of Llama Scout's historical community
          record and are attributed to an anonymous account
          such as
          "<strong>
            <?= delete_account_e(
                delete_account_anonymous_username(
                    $userId
                )
            ) ?>
          </strong>"
          or Deleted User.
        </li>

        <li>
          Badges, contribution points, Scout status, Master
          Scout status, credentials recorded as account
          achievements, and other Llama Scout status or
          recognition are immediately forfeited.
        </li>

        <li>
          Paid, complimentary, promotional, Scout-earned, or
          otherwise granted membership access ends
          immediately. Membership access cannot be restored
          to the deleted account or transferred to another
          account.
        </li>

        <li>
          If you have an active Stripe subscription, Llama
          Scout will attempt to cancel it immediately before
          anonymizing your account. Account deletion does not
          automatically create a refund for unused membership
          time except where required by applicable law or an
          applicable Llama Scout policy.
        </li>

        <li>
          Orders, payments, refunds, disputes, security logs,
          moderation history, accounting records, and other
          information may be retained when reasonably needed
          for legal, financial, security, fraud-prevention,
          dispute-resolution, or service-integrity purposes.
        </li>

        <li>
          Draft or non-approved contribution material may be
          removed as part of account deletion.
        </li>

      </ul>

    </section>


    <section
      class="
        delete-account-card
        delete-account-card--danger
      "
    >

      <h2>
        So this is it...
      </h2>

      <p>
        To prevent accidental or unauthorized deletion, enter
        your current username and password, then acknowledge
        every statement below.
      </p>


      <form
        method="post"
        class="delete-account-form"
        autocomplete="off"
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= delete_account_e(
              $csrfToken
          ) ?>"
        >


        <div class="delete-account-field">

          <label for="username_confirm">
            Type your username
          </label>

          <input
            id="username_confirm"
            name="username_confirm"
            type="text"
            autocomplete="off"
            autocapitalize="none"
            spellcheck="false"
            placeholder="<?= delete_account_e(
                (string) $account['username']
            ) ?>"
            required
          >

          <small>
            Enter
            <strong>
              <?= delete_account_e(
                  (string) $account['username']
              ) ?>
            </strong>
            exactly as shown.
          </small>

        </div>


        <div class="delete-account-field">

          <label for="password_confirm">
            Current password
          </label>

          <input
            id="password_confirm"
            name="password_confirm"
            type="password"
            autocomplete="current-password"
            required
          >

          <small>
            Your password is checked securely and is not
            included in the deletion record.
          </small>

        </div>


        <div class="delete-account-checks">

          <label class="delete-account-check">

            <input
              type="checkbox"
              name="confirm_permanent"
              value="1"
              required
            >

            <span>
              I understand that deleting my account is
              immediate, permanent, and irreversible.
            </span>

          </label>


          <label class="delete-account-check">

            <input
              type="checkbox"
              name="confirm_contributions"
              value="1"
              required
            >

            <span>
              I understand that published Places and approved
              contributions I made may remain on Llama Scout
              and will be anonymized rather than deleted.
            </span>

          </label>


          <label class="delete-account-check">

            <input
              type="checkbox"
              name="confirm_forfeit_status"
              value="1"
              required
            >

            <span>
              I understand that my badges, points, Scout or
              Master Scout status, and other earned Llama
              Scout recognition are immediately forfeited.
            </span>

          </label>


          <label class="delete-account-check">

            <input
              type="checkbox"
              name="confirm_membership"
              value="1"
              required
            >

            <span>
              I understand that any paid, complimentary,
              promotional, or earned membership access ends
              immediately and cannot be restored or
              transferred to another account.
            </span>

          </label>


          <label class="delete-account-check">

            <input
              type="checkbox"
              name="confirm_retention"
              value="1"
              required
            >

            <span>
              I understand that Llama Scout may retain
              transaction, legal, security, moderation,
              accounting, and historical records when there
              is a legitimate reason to do so.
            </span>

          </label>


          <label
            class="
              delete-account-check
              delete-account-check--final
            "
          >

            <input
              type="checkbox"
              name="confirm_request"
              value="1"
              required
            >

            <span>
              I am the owner of this account and I am
              requesting that Llama Scout delete and
              anonymize it now.
            </span>

          </label>

        </div>


        <div class="delete-account-submit">

          <button
            type="submit"
            class="delete-account-button delete-account-button--danger"
            <?= $privilegedAccount
                ? 'disabled'
                : ''
            ?>
            onclick="
              return confirm(
                'Delete and anonymize your Llama Scout account now? This is immediate and cannot be undone. The herd is really going to miss you.'
              );
            "
          >
            <i
              class="fa-solid fa-trash-can"
              aria-hidden="true"
            ></i>

            Permanently Delete My Account
          </button>

        </div>

      </form>

    </section>


  <?php endif; ?>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
