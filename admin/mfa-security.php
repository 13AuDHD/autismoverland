<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   PRIVILEGED MFA MANAGEMENT
   admin/mfa-security.php

   Owner-only recovery tool for another privileged account.

   This page:
   - shows MFA enrollment status
   - shows remaining recovery-code count
   - allows an Owner to reset another Admin/Owner's MFA
   - requires the acting Owner's password
   - requires a fresh acting Owner TOTP
   - requires exact target username confirmation

   The acting Owner cannot reset their own MFA here.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/mfa.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'owner'
);


start_llama_session();


$db =
    db();


$currentOwner =
    current_user();


$currentOwnerId =
    (int) (
        $currentOwner[
            'id'
        ]
        ?? 0
    );


if (
    $currentOwnerId < 1
) {

    header(
        'Location: https://account.llamascout.com/login.php'
    );


    exit;
}


/* =========================================================
   HELPERS
   ========================================================= */


function mfa_security_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function mfa_security_roles(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT
                r.slug

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id = ur.role_id

            WHERE ur.user_id = ?

            ORDER BY r.slug ASC
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        array_map(
            'strval',
            $stmt->fetchAll(
                PDO::FETCH_COLUMN
            )
        );
}


/* =========================================================
   TARGET ACCOUNT
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

    header(
        'Location: /users.php'
    );


    exit;
}


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            email,
            username,
            display_name,
            status

        FROM users

        WHERE id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$targetUser =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (!$targetUser) {

    header(
        'Location: https://llamascout.com/safety.php?reason=not-found'
    );


    exit;
}


$targetRoles =
    mfa_security_roles(
        $db,
        $userId
    );


$targetIsOwner =
    in_array(
        'owner',
        $targetRoles,
        true
    );


$targetIsAdmin =
    in_array(
        'admin',
        $targetRoles,
        true
    );


$targetIsPrivileged =
    $targetIsOwner
    ||
    $targetIsAdmin;


if (!$targetIsPrivileged) {

    header(
        'Location: https://llamascout.com/safety.php?reason=permission'
    );


    exit;
}


$targetUsername =
    trim(
        (string) (
            $targetUser[
                'username'
            ]
            ?? ''
        )
    );


$targetDisplayName =
    trim(
        (string) (
            $targetUser[
                'display_name'
            ]
            ?:
            $targetUsername
            ?:
            $targetUser[
                'email'
            ]
        )
    );


/* =========================================================
   ACTING OWNER PASSWORD
   ========================================================= */


$passwordStmt =
    $db->prepare(
        '
        SELECT password_hash

        FROM users

        WHERE id = ?

        LIMIT 1
        '
    );


$passwordStmt->execute([
    $currentOwnerId
]);


$currentOwnerPasswordHash =
    (string) (
        $passwordStmt
            ->fetchColumn()
        ?: ''
    );


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'admin_mfa_security_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_mfa_security_csrf'
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
        'admin_mfa_security_csrf'
    ];


/* =========================================================
   STATUS
   ========================================================= */


$mfaEnabled =
    llama_mfa_is_enabled(
        $userId,
        $db
    );


$mfaRecord =
    llama_mfa_record(
        $userId,
        $db
    );


$recoveryCodeCount =
    $mfaEnabled
        ? llama_mfa_recovery_code_count(
            $userId,
            $db
        )
        : 0;


$message =
    '';


$error =
    '';


/* =========================================================
   RESET MFA
   ========================================================= */


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    $submittedToken =
        (string) (
            $_POST[
                'csrf_token'
            ]
            ?? ''
        );


    $action =
        trim(
            (string) (
                $_POST[
                    'action'
                ]
                ?? ''
            )
        );


    $ownerPassword =
        (string) (
            $_POST[
                'owner_password'
            ]
            ?? ''
        );


    $ownerTotp =
        trim(
            (string) (
                $_POST[
                    'owner_totp'
                ]
                ?? ''
            )
        );


    $usernameConfirmation =
        trim(
            (string) (
                $_POST[
                    'confirm_username'
                ]
                ?? ''
            )
        );


    $acknowledged =
        isset(
            $_POST[
                'acknowledge_reset'
            ]
        );


    if (
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';


    } elseif (
        $action !==
        'reset_mfa'
    ) {

        $error =
            'That MFA security action is not valid.';


    } elseif (
        $userId ===
        $currentOwnerId
    ) {

        $error =
            'You cannot reset your own MFA from this recovery tool.';


    } elseif (
        !$mfaEnabled
    ) {

        $error =
            'MFA is not currently enabled for this account.';


    } elseif (
        $targetUsername === ''
    ) {

        $error =
            'This account needs a username before MFA can be reset here.';


    } elseif (
        !hash_equals(
            $targetUsername,
            $usernameConfirmation
        )
    ) {

        $error =
            'The username confirmation does not match the target account.';


    } elseif (
        !$acknowledged
    ) {

        $error =
            'You must acknowledge the MFA reset warning before continuing.';


    } elseif (
        $currentOwnerPasswordHash === ''
        ||
        !password_verify(
            $ownerPassword,
            $currentOwnerPasswordHash
        )
    ) {

        $error =
            'Your Owner password is not correct.';


    } elseif (
        !llama_mfa_is_enabled(
            $currentOwnerId,
            $db
        )
    ) {

        $error =
            'Your Owner account must have MFA enabled to reset another privileged account.';


    } elseif (
        !llama_mfa_authenticate_totp(
            $currentOwnerId,
            $ownerTotp,
            $db
        )
    ) {

        $error =
            'That Owner authentication code is not valid. Wait for a new code and try again.';


    } else {

        try {

            /*
             * llama_mfa_reset() handles its own transaction.
             */

            llama_mfa_reset(
                $userId,
                $db
            );


            /*
             * Remove all persistent login tokens too.
             */

            llama_mfa_invalidate_remember_tokens(
                $userId,
                $db
            );


            $mfaEnabled =
                false;


            $mfaRecord =
                null;


            $recoveryCodeCount =
                0;


            $_SESSION[
                'admin_mfa_security_csrf'
            ] =
                bin2hex(
                    random_bytes(
                        32
                    )
                );


            $csrfToken =
                (string)
                $_SESSION[
                    'admin_mfa_security_csrf'
                ];


            $message =
                'MFA was reset. Existing remembered logins were revoked. This account must complete fresh MFA enrollment before privileged access can continue.';


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout MFA reset error. Owner #'
                .
                $currentOwnerId
                .
                ', target #'
                .
                $userId
                .
                ': '
                .
                $exception
                    ->getMessage()
            );


            $error =
                'MFA could not be reset. No further changes were made.';
        }
    }
}


/* =========================================================
   DISPLAY
   ========================================================= */


$targetRoleLabel =
    $targetIsOwner
        ? 'Owner'
        : 'Admin';


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
    MFA Security | Llama Scout Admin
  </title>


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

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">

          <i
            class="fa-solid fa-shield-halved"
            aria-hidden="true"
          ></i>

          Privileged Account Security

        </p>


        <h1>
          MFA Security
        </h1>


        <p>
          Review and recover multi-factor authentication for
          <?= mfa_security_e(
              $targetDisplayName
          ) ?>.
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
          Back to User
        </a>

      </div>

    </div>

  </section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <?php if (
      $message !== ''
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >
      <p>
        <?= mfa_security_e(
            $message
        ) ?>
      </p>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >
      <p>
        <?= mfa_security_e(
            $error
        ) ?>
      </p>
    </div>

  <?php endif; ?>


  <div class="admin-mfa-security-layout">


    <section class="admin-panel">

      <div class="admin-panel-header">

        <div>

          <h2>
            MFA Status
          </h2>

          <p>
            Current privileged authentication state.
          </p>

        </div>

      </div>


      <div class="admin-detail-list">


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Account
          </div>

          <div class="admin-detail-value">
            <?= mfa_security_e(
                $targetDisplayName
            ) ?>
          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Username
          </div>

          <div class="admin-detail-value">

            <?php if (
                $targetUsername !== ''
            ): ?>

              @<?= mfa_security_e(
                  $targetUsername
              ) ?>

            <?php else: ?>

              <span class="admin-muted">
                No username set
              </span>

            <?php endif; ?>

          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Privileged Role
          </div>

          <div class="admin-detail-value">

            <span
              class="
                admin-user-badge
                admin-user-role
                admin-user-role--admin
              "
            >

              <i
                class="<?= $targetIsOwner
                    ? 'fa-solid fa-crown'
                    : 'fa-solid fa-shield-halved'
                ?>"
                aria-hidden="true"
              ></i>

              <?= mfa_security_e(
                  $targetRoleLabel
              ) ?>

            </span>

          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            MFA
          </div>

          <div class="admin-detail-value">

            <?php if (
                $mfaEnabled
            ): ?>

              <span
                class="
                  admin-badge
                  admin-badge--success
                "
              >
                Enabled
              </span>

            <?php else: ?>

              <span
                class="
                  admin-badge
                  admin-badge--warning
                "
              >
                Enrollment Required
              </span>

            <?php endif; ?>

          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Enabled
          </div>

          <div class="admin-detail-value">

            <?= $mfaEnabled
                && !empty(
                    $mfaRecord[
                        'enabled_at'
                    ]
                )
                    ? mfa_security_e(
                        (string)
                        $mfaRecord[
                            'enabled_at'
                        ]
                    )
                    : 'Not enabled'
            ?>

          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Recovery Codes
          </div>

          <div class="admin-detail-value">

            <?php if (
                $mfaEnabled
            ): ?>

              <strong>
                <?= $recoveryCodeCount ?>
              </strong>
              unused

            <?php else: ?>

              None

            <?php endif; ?>

          </div>

        </div>


      </div>

    </section>


    <section class="admin-panel admin-mfa-reset-panel">

      <div class="admin-panel-header">

        <div>

          <h2>
            Reset MFA
          </h2>

          <p>
            Emergency recovery when a privileged user loses
            their authenticator or recovery codes.
          </p>

        </div>

      </div>


      <?php if (
          $userId ===
          $currentOwnerId
      ): ?>

        <div class="admin-form">

          <div class="admin-mfa-lockout-warning">

            <i
              class="fa-solid fa-lock"
              aria-hidden="true"
            ></i>

            <div>

              <strong>
                Self-reset is blocked
              </strong>

              <p>
                You cannot reset your own Owner MFA from this
                recovery page. Another Owner must recover your
                account so one compromised session cannot
                remove its own second factor.
              </p>

            </div>

          </div>

        </div>


      <?php elseif (
          !$mfaEnabled
      ): ?>

        <div class="admin-form">

          <div class="admin-mfa-enrollment-needed">

            <i
              class="fa-solid fa-key"
              aria-hidden="true"
            ></i>

            <div>

              <strong>
                MFA enrollment is already required
              </strong>

              <p>
                There is no existing MFA configuration to
                reset. This account must enroll during its
                next privileged sign-in.
              </p>

            </div>

          </div>

        </div>


      <?php elseif (
          $targetUsername === ''
      ): ?>

        <div class="admin-form">

          <p class="admin-field-help">
            This account needs a username before MFA can be
            reset through this recovery tool.
          </p>

        </div>


      <?php else: ?>

        <form
          method="post"
          class="admin-form admin-mfa-reset-form"
          autocomplete="off"
        >

          <input
            type="hidden"
            name="action"
            value="reset_mfa"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= $userId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= mfa_security_e(
                $csrfToken
            ) ?>"
          >


          <div class="admin-mfa-reset-warning">

            <i
              class="fa-solid fa-triangle-exclamation"
              aria-hidden="true"
            ></i>

            <div>

              <strong>
                This removes the existing second factor
              </strong>

              <p>
                The authenticator secret and every unused
                recovery code for
                @<?= mfa_security_e(
                    $targetUsername
                ) ?>
                will be destroyed. Their next privileged login
                must enroll a new authenticator.
              </p>

            </div>

          </div>


          <div class="admin-field">

            <label for="confirm_username">
              Type the target username
            </label>

            <input
              id="confirm_username"
              name="confirm_username"
              type="text"
              autocomplete="off"
              autocapitalize="none"
              spellcheck="false"
              placeholder="<?= mfa_security_e(
                  $targetUsername
              ) ?>"
              required
            >

            <p class="admin-field-help">
              Type
              <strong>
                <?= mfa_security_e(
                    $targetUsername
                ) ?>
              </strong>
              exactly.
            </p>

          </div>


          <div class="admin-field">

            <label for="owner_password">
              Your Owner password
            </label>

            <input
              id="owner_password"
              name="owner_password"
              type="password"
              autocomplete="current-password"
              required
            >

          </div>


          <div class="admin-field">

            <label for="owner_totp">
              Your fresh 6-digit Owner authentication code
            </label>

            <input
              id="owner_totp"
              name="owner_totp"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              pattern="[0-9]{6}"
              minlength="6"
              maxlength="6"
              placeholder="000000"
              required
            >

            <p class="admin-field-help">
              Recovery codes cannot authorize an MFA reset.
            </p>

          </div>


          <label class="admin-checkbox admin-mfa-reset-ack">

            <input
              type="checkbox"
              name="acknowledge_reset"
              value="1"
              required
            >

            <span>
              I understand this destroys the target account's
              current authenticator setup and recovery codes.
            </span>

          </label>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="
                admin-button
                admin-mfa-reset-button
              "
            >

              <i
                class="fa-solid fa-rotate"
                aria-hidden="true"
              ></i>

              Reset MFA

            </button>

          </div>

        </form>

      <?php endif; ?>


    </section>


  </div>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
