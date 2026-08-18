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


require_role(
    'admin'
);


$adminUser =
    current_user();


start_llama_session();


$db =
    db();


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


function fetch_user(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                email,
                username,
                display_name,
                timezone,
                status,
                email_verified_at,
                created_at,
                last_login_at

            FROM users

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?: [];

}


/* =========================================================
   CREATE EMAIL VERIFICATION
   ========================================================= */

function create_verification(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();


    try {

        /*
         * Expire any unused verification links
         * already associated with this account.
         */

        $stmt =
            $db->prepare(
                '
                UPDATE email_verifications

                SET used_at =
                    CURRENT_TIMESTAMP

                WHERE user_id = ?
                  AND used_at IS NULL
                '
            );


        $stmt->execute([
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


        $stmt =
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


        $stmt->execute([
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
   CREATE PASSWORD RESET
   ========================================================= */

function create_password_reset(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();


    try {

        /*
         * Expire any unused reset links first.
         */

        $expireStmt =
            $db->prepare(
                '
                UPDATE password_resets

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
                INSERT INTO password_resets
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
                        INTERVAL 60 MINUTE
                    )
                )
                '
            );


        $insertStmt->execute([
            $user['id'],
            $tokenHash
        ]);


        $db->commit();


        $resetUrl =
            'https://account.llamascout.com/reset-password.php?token='
            .
            rawurlencode(
                $token
            );


        $name =
            trim(
                (string) (
                    $user[
                        'display_name'
                    ]
                    ?: $user[
                        'username'
                    ]
                    ?: 'Scout'
                )
            );


        $subject =
            'Reset your Llama Scout password';


        $message =
            "Hi {$name},\n\n"
            .
            "Llama Scout support sent you a secure password reset link.\n\n"
            .
            "Use the link below to choose a new password:\n\n"
            .
            $resetUrl
            .
            "\n\n"
            .
            "This link expires in 60 minutes and can only be used once.\n\n"
            .
            "If you were not expecting this email, you can ignore it.\n\n"
            .
            "Llama Scout";


        return send_llama_mail(
            $user[
                'email'
            ],
            $subject,
            $message
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
   USER ID
   ========================================================= */

$userId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'user_id'
        ]
        ??
        0
    );


if (
    $userId < 1
) {

    http_response_code(
        400
    );


    exit(
        'A valid user ID is required.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_user_account_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_user_account_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'admin_user_account_csrf'
    ];


/* =========================================================
   LOAD USER
   ========================================================= */

$managedUser =
    fetch_user(
        $db,
        $userId
    );


if (
    !$managedUser
) {

    http_response_code(
        404
    );


    exit(
        'User not found.'
    );

}


/* =========================================================
   POST ACTIONS
   ========================================================= */

$message =
    '';


$error =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
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


        /* =================================================
           UPDATE ACCOUNT
           ================================================= */

        if (
            $action ===
            'update_account'
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


            $usernamePolicy =
                username_policy_check(
                    $username
                );


            if (
                !$usernamePolicy[
                    'allowed'
                ]
            ) {

                $error =
                    $usernamePolicy[
                        'reason'
                    ];

            } elseif (
                $displayName === ''
                ||
                mb_strlen(
                    $displayName
                ) < 2
                ||
                mb_strlen(
                    $displayName
                ) > 100
            ) {

                $error =
                    'Display name must be between 2 and 100 characters.';

            } elseif (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $error =
                    'Enter a valid email address.';

            }


            if (
                $error === ''
                &&
                !llama_timezone_is_valid(
                    $timezone
                )
            ) {

                $error =
                    'Choose a valid time zone.';

            }


            /* =============================================
               USERNAME UNIQUENESS
               ============================================= */

            if (
                $error === ''
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

                    $error =
                        'That username is already taken.';

                }

            }


            /* =============================================
               EMAIL UNIQUENESS
               ============================================= */

            if (
                $error === ''
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

                    $error =
                        'An account already exists with that email address.';

                }

            }


            /* =============================================
               SAVE ACCOUNT
               ============================================= */

            if (
                $error === ''
            ) {

                $emailChanged =
                    $email
                    !==
                    strtolower(
                        (string)
                        $managedUser[
                            'email'
                        ]
                    );


                try {

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


                    $managedUser =
                        fetch_user(
                            $db,
                            $userId
                        );


                    if (
                        $emailChanged
                    ) {

                        $sent =
                            create_verification(
                                $db,
                                $managedUser
                            );


                        $message =
                            $sent
                                ? 'Account updated. A verification email was sent to the new address.'
                                : 'Account updated, but the verification email could not be sent.';

                    } else {

                        $message =
                            'Account information updated.';

                    }

                } catch (
                    Throwable $exception
                ) {

                    error_log(
                        'Llama Scout admin account edit error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The account information could not be updated.';

                }

            }


        /* =================================================
           PASSWORD RESET
           ================================================= */

        } elseif (
            $action ===
            'send_password_reset'
        ) {

            try {

                $sent =
                    create_password_reset(
                        $db,
                        $managedUser
                    );


                $message =
                    $sent
                        ? 'Password reset email sent to '
                          .
                          $managedUser[
                              'email'
                          ]
                          .
                          '.'
                        : 'The reset link was created, but the email could not be sent.';

            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout admin password reset error: '
                    .
                    $exception
                        ->getMessage()
                );


                $error =
                    'The password reset email could not be created.';

            }


        /* =================================================
           VERIFICATION EMAIL
           ================================================= */

        } elseif (
            $action ===
            'send_verification'
        ) {

            try {

                $sent =
                    create_verification(
                        $db,
                        $managedUser
                    );


                $message =
                    $sent
                        ? 'Verification email sent to '
                          .
                          $managedUser[
                              'email'
                          ]
                          .
                          '.'
                        : 'A verification link was created, but the email could not be sent.';

            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout admin verification email error: '
                    .
                    $exception
                        ->getMessage()
                );


                $error =
                    'The verification email could not be created.';

            }

        } else {

            $error =
                'That admin action is not supported.';

        }

    }

}


/* =========================================================
   REFRESH USER AFTER ACTIONS
   ========================================================= */

$managedUser =
    fetch_user(
        $db,
        $userId
    );


$displayHeading =
    trim(
        (string) (
            $managedUser[
                'display_name'
            ]
            ?: $managedUser[
                'username'
            ]
            ?: $managedUser[
                'email'
            ]
        )
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
    Edit <?= e(
        $displayHeading
    ) ?>
    | Llama Scout Admin
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >


  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap"
    rel="stylesheet"
  >


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/admin.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="https://llamascout.com/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="https://llamascout.com/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="https://llamascout.com/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="https://llamascout.com/icons/site.webmanifest"
  >

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <!-- =====================================================
       PAGE INTRO
       ===================================================== -->

  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">
          User Management
        </p>

        <h1>
          Edit Account
        </h1>

        <p>
          <?= e(
              $displayHeading
          ) ?>

          &middot;

          User
          #<?= $userId ?>
        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/user.php?id=<?= $userId ?>"
        >

          <i
            class="fa-solid fa-arrow-left"
            aria-hidden="true"
          ></i>

          User Overview

        </a>

      </div>

    </div>

  </section>


  <!-- =====================================================
       ADMIN NAVIGATION
       ===================================================== -->

  <nav
    class="admin-nav"
    aria-label="Admin navigation"
  >

    <div class="admin-nav-inner">

      <a href="/">

        <i
          class="fa-solid fa-campground"
          aria-hidden="true"
        ></i>

        Basecamp

      </a>


      <a href="/places.php">

        <i
          class="fa-solid fa-location-dot"
          aria-hidden="true"
        ></i>

        Places

      </a>


      <a href="/submissions.php">

        <i
          class="fa-solid fa-inbox"
          aria-hidden="true"
        ></i>

        Submissions

      </a>


      <a
        class="is-active"
        href="/users.php"
      >

        <i
          class="fa-solid fa-users"
          aria-hidden="true"
        ></i>

        Users

      </a>


      <a href="/import-places.php">

        <i
          class="fa-solid fa-file-import"
          aria-hidden="true"
        ></i>

        Import

      </a>

    </div>

  </nav>


  <!-- =====================================================
       NOTICES
       ===================================================== -->

  <?php if (
      $message
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>
        <?= e(
            $message
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <?php if (
      $error
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >

      <p>
        <?= e(
            $error
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       ACCOUNT INFORMATION
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Account Information
        </h2>

        <p>
          Update the member's public account identity,
          email address, and time zone.
        </p>

      </div>

    </div>


    <form
      method="post"
      class="admin-form"
    >

      <input
        type="hidden"
        name="action"
        value="update_account"
      >

      <input
        type="hidden"
        name="user_id"
        value="<?= $userId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <div class="admin-form-grid">


        <div class="admin-field">

          <label for="display_name">
            Display Name
          </label>

          <input
            id="display_name"
            name="display_name"
            type="text"
            maxlength="100"
            value="<?= e(
                $managedUser[
                    'display_name'
                ]
            ) ?>"
            required
          >

        </div>


        <div class="admin-field">

          <label for="username">
            Username
          </label>

          <input
            id="username"
            name="username"
            type="text"
            minlength="4"
            maxlength="16"
            value="<?= e(
                $managedUser[
                    'username'
                ]
            ) ?>"
            required
          >

          <p class="admin-field-help">
            4 to 16 characters.
            Letters, numbers, and underscores only.
          </p>

        </div>


        <div class="admin-field">

          <label for="email">
            Email Address
          </label>

          <input
            id="email"
            name="email"
            type="email"
            maxlength="255"
            value="<?= e(
                $managedUser[
                    'email'
                ]
            ) ?>"
            required
          >

          <p class="admin-field-help">
            Changing the email address clears
            its verified status and sends a new
            verification email.
          </p>

        </div>


        <div class="admin-field">

          <label for="timezone">
            Time Zone
          </label>

          <select
            id="timezone"
            name="timezone"
            required
          >

            <?php foreach (
                llama_timezones()
                as
                $zone =>
                $label
            ): ?>

              <option
                value="<?= e(
                    $zone
                ) ?>"
                <?= llama_user_timezone(
                    $managedUser
                ) === $zone
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

          <p class="admin-field-help">
            Controls how dates and times
            are shown for this user.
          </p>

        </div>


      </div>


      <div class="admin-form-actions">

        <button
          type="submit"
          class="admin-button"
        >

          <i
            class="fa-solid fa-floppy-disk"
            aria-hidden="true"
          ></i>

          Save Account Information

        </button>

      </div>

    </form>

  </section>


  <!-- =====================================================
       EMAIL VERIFICATION
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Email Verification
        </h2>

        <p>
          Review verification status or send
          a fresh verification link.
        </p>

      </div>


      <?php if (
          !empty(
              $managedUser[
                  'email_verified_at'
              ]
          )
      ): ?>

        <span
          class="
            admin-badge
            admin-badge--success
          "
        >

          Verified

        </span>

      <?php else: ?>

        <span
          class="
            admin-badge
            admin-badge--warning
          "
        >

          Verification Required

        </span>

      <?php endif; ?>

    </div>


    <div class="admin-detail-list">


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Email
        </div>

        <div class="admin-detail-value">

          <?= e(
              $managedUser[
                  'email'
              ]
          ) ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Verification
        </div>

        <div class="admin-detail-value">

          <?php if (
              !empty(
                  $managedUser[
                      'email_verified_at'
                  ]
              )
          ): ?>

            Verified

          <?php else: ?>

            Not yet verified

          <?php endif; ?>

        </div>

      </div>


    </div>


    <form
      method="post"
      class="admin-form"
      style="margin-top: 20px;"
    >

      <input
        type="hidden"
        name="action"
        value="send_verification"
      >

      <input
        type="hidden"
        name="user_id"
        value="<?= $userId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <div class="admin-form-actions">

        <button
          type="submit"
          class="
            admin-button
            admin-button--secondary
          "
        >

          <i
            class="fa-solid fa-envelope"
            aria-hidden="true"
          ></i>

          Send Verification Email

        </button>

      </div>

    </form>

  </section>


  <!-- =====================================================
       PASSWORD ASSISTANCE
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Password Assistance
        </h2>

        <p>
          Send the user a secure one-time
          password reset link.
        </p>

      </div>

    </div>


    <div
      class="
        admin-notice
        admin-notice--info
      "
    >

      <p>
        Llama Scout never displays or sends
        a user's password. The reset link is sent
        to the account email, expires after
        60 minutes, and can only be used once.
      </p>

    </div>


    <form
      method="post"
      class="admin-form"
    >

      <input
        type="hidden"
        name="action"
        value="send_password_reset"
      >

      <input
        type="hidden"
        name="user_id"
        value="<?= $userId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <div class="admin-form-actions">

        <button
          type="submit"
          class="admin-button"
        >

          <i
            class="fa-solid fa-key"
            aria-hidden="true"
          ></i>

          Send Password Reset Link

        </button>

      </div>

    </form>

  </section>


  <!-- =====================================================
       QUICK LINKS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/user.php?id=<?= $userId ?>">
      User Overview
    </a>

    <a href="/users.php">
      All Users
    </a>

    <a href="/">
      Basecamp
    </a>

  </div>


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
