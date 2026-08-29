<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   OWNER ACCESS MANAGEMENT
   admin/owner-access.php

   Owner promotion and demotion are intentionally separate
   from ordinary role controls.

   Security requirements:
   - Current operator must already be an Owner.
   - Current Owner password must be re-entered.
   - Current Owner TOTP must be re-entered.
   - Target username must be typed exactly.
   - Last remaining Owner cannot be demoted.
   - Target Remember Me tokens are revoked on any Owner
     privilege change.
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


function owner_access_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function owner_access_roles(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT
                r.id,
                r.slug

            FROM roles r

            INNER JOIN user_roles ur
              ON ur.role_id =
                 r.id

            WHERE ur.user_id = ?

            ORDER BY
                r.slug ASC
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


function owner_access_owner_role_id(
    PDO $db
): int {

    $stmt =
        $db->prepare(
            '
            SELECT id

            FROM roles

            WHERE slug = \'owner\'

            LIMIT 1
            '
        );


    $stmt->execute();


    return
        (int)
        $stmt->fetchColumn();
}


function owner_access_owner_ids_for_update(
    PDO $db,
    int $ownerRoleId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT user_id

            FROM user_roles

            WHERE role_id = ?

            ORDER BY user_id ASC

            FOR UPDATE
            '
        );


    $stmt->execute([
        $ownerRoleId
    ]);


    return
        array_map(
            'intval',
            $stmt->fetchAll(
                PDO::FETCH_COLUMN
            )
        );
}


/* =========================================================
   TARGET USER
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
    owner_access_roles(
        $db,
        $userId
    );


$targetRoleSlugs =
    array_column(
        $targetRoles,
        'slug'
    );


$targetIsOwner =
    in_array(
        'owner',
        $targetRoleSlugs,
        true
    );


$targetIsAdmin =
    in_array(
        'admin',
        $targetRoleSlugs,
        true
    );


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
   CURRENT OWNER PASSWORD
   ========================================================= */


$currentPasswordStmt =
    $db->prepare(
        '
        SELECT password_hash

        FROM users

        WHERE id = ?

        LIMIT 1
        '
    );


$currentPasswordStmt->execute([
    $currentOwnerId
]);


$currentPasswordHash =
    (string) (
        $currentPasswordStmt
            ->fetchColumn()
        ?: ''
    );


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'owner_access_csrf'
        ]
    )
) {

    $_SESSION[
        'owner_access_csrf'
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
        'owner_access_csrf'
    ];


/* =========================================================
   STATE
   ========================================================= */


$message =
    '';


$error =
    '';


/* =========================================================
   POST
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
                'acknowledge_owner_access'
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
        !in_array(
            $action,
            [
                'promote_owner',
                'demote_owner',
            ],
            true
        )
    ) {

        $error =
            'That Owner access action is not valid.';


    } elseif (
        $targetUsername === ''
    ) {

        $error =
            'This account needs a username before Owner access can be changed.';


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
            'You must acknowledge the Owner access warning before continuing.';


    } elseif (
        $currentPasswordHash === ''
        ||
        !password_verify(
            $ownerPassword,
            $currentPasswordHash
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
            'Your Owner account must have MFA enabled before Owner access can be changed.';


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

            $db->beginTransaction();


            $ownerRoleId =
                owner_access_owner_role_id(
                    $db
                );


            if (
                $ownerRoleId < 1
            ) {

                throw new RuntimeException(
                    'The Owner role is not configured.'
                );
            }


            /*
             * Lock all Owner assignments before making a
             * privilege change. This keeps the last-Owner
             * safety check reliable even if two requests
             * arrive at nearly the same time.
             */

            $ownerIds =
                owner_access_owner_ids_for_update(
                    $db,
                    $ownerRoleId
                );


            $targetCurrentlyOwner =
                in_array(
                    $userId,
                    $ownerIds,
                    true
                );


            if (
                $action ===
                'promote_owner'
            ) {

                if (
                    $targetCurrentlyOwner
                ) {

                    throw new RuntimeException(
                        'This account is already an Owner.'
                    );
                }


                $insert =
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


                $insert->execute([
                    $userId,
                    $ownerRoleId,
                ]);


                llama_mfa_invalidate_remember_tokens(
                    $userId,
                    $db
                );


                $db->commit();


                $message =
                    'Owner access granted. Existing remembered logins were revoked, and MFA is required for privileged access.';


            } else {

                if (
                    !$targetCurrentlyOwner
                ) {

                    throw new RuntimeException(
                        'This account is not currently an Owner.'
                    );
                }


                if (
                    count(
                        $ownerIds
                    )
                    <= 1
                ) {

                    throw new RuntimeException(
                        'The last remaining Owner cannot be demoted.'
                    );
                }


                $delete =
                    $db->prepare(
                        '
                        DELETE FROM user_roles

                        WHERE user_id = ?
                          AND role_id = ?
                        '
                    );


                $delete->execute([
                    $userId,
                    $ownerRoleId,
                ]);


                llama_mfa_invalidate_remember_tokens(
                    $userId,
                    $db
                );


                $db->commit();


                $message =
                    'Owner access removed. Existing remembered logins were revoked.';
            }


            /*
             * Reload role state after a successful change.
             */

            $targetRoles =
                owner_access_roles(
                    $db,
                    $userId
                );


            $targetRoleSlugs =
                array_column(
                    $targetRoles,
                    'slug'
                );


            $targetIsOwner =
                in_array(
                    'owner',
                    $targetRoleSlugs,
                    true
                );


            $targetIsAdmin =
                in_array(
                    'admin',
                    $targetRoleSlugs,
                    true
                );


            $_SESSION[
                'owner_access_csrf'
            ] =
                bin2hex(
                    random_bytes(
                        32
                    )
                );


            $csrfToken =
                (string)
                $_SESSION[
                    'owner_access_csrf'
                ];


        } catch (
            Throwable $exception
        ) {

            if (
                $db->inTransaction()
            ) {

                $db->rollBack();
            }


            error_log(
                'Llama Scout Owner access change error. Operator #'
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
                $exception
                    ->getMessage();
        }
    }
}


/* =========================================================
   DISPLAY ROLE
   ========================================================= */


$targetRoleLabel =
    $targetIsOwner
        ? 'Owner'
        : (
            $targetIsAdmin
                ? 'Admin'
                : 'User'
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
    Owner Access | Llama Scout Admin
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
            class="fa-solid fa-crown"
            aria-hidden="true"
          ></i>

          Owner Security

        </p>


        <h1>
          Owner Access
        </h1>


        <p>
          Promote or demote the Owner role for
          <?= owner_access_e(
              $targetDisplayName
          ) ?>.
          Owner changes require your password and a fresh
          authenticator code.
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
        <?= owner_access_e(
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
        <?= owner_access_e(
            $error
        ) ?>
      </p>
    </div>

  <?php endif; ?>


  <div class="owner-access-layout">


    <section class="admin-panel">

      <div class="admin-panel-header">

        <div>

          <h2>
            Target Account
          </h2>

          <p>
            Confirm the account before changing its highest
            level of access.
          </p>

        </div>

      </div>


      <div class="admin-detail-list">


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            User
          </div>

          <div class="admin-detail-value">
            <?= owner_access_e(
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

              @<?= owner_access_e(
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
            Email
          </div>

          <div class="admin-detail-value">
            <?= owner_access_e(
                $targetUser[
                    'email'
                ]
            ) ?>
          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Current Access
          </div>

          <div class="admin-detail-value">

            <span
              class="
                admin-user-badge
                admin-user-role
                <?= $targetIsOwner
                    || $targetIsAdmin
                        ? 'admin-user-role--admin'
                        : ''
                ?>
              "
            >

              <?php if (
                  $targetIsOwner
              ): ?>

                <i
                  class="fa-solid fa-crown"
                  aria-hidden="true"
                ></i>

              <?php elseif (
                  $targetIsAdmin
              ): ?>

                <i
                  class="fa-solid fa-shield-halved"
                  aria-hidden="true"
                ></i>

              <?php endif; ?>

              <?= owner_access_e(
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
                llama_mfa_is_enabled(
                    $userId,
                    $db
                )
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


      </div>

    </section>


    <section
      class="
        admin-panel
        owner-access-security-panel
      "
    >

      <div class="admin-panel-header">

        <div>

          <h2>

            <?php if (
                $targetIsOwner
            ): ?>

              Remove Owner Access

            <?php else: ?>

              Promote to Owner

            <?php endif; ?>

          </h2>


          <p>

            <?php if (
                $targetIsOwner
            ): ?>

              Removing Owner access changes this account's
              highest authority immediately.

            <?php else: ?>

              Owner access grants full Basecamp authority and
              should only be assigned when necessary.

            <?php endif; ?>

          </p>

        </div>

      </div>


      <?php if (
          $targetUsername === ''
      ): ?>

        <div class="admin-notice admin-notice--error">

          <p>
            This account cannot be promoted or demoted here
            until it has a username.
          </p>

        </div>


      <?php else: ?>

        <form
          method="post"
          class="admin-form owner-access-form"
          autocomplete="off"
        >

          <input
            type="hidden"
            name="user_id"
            value="<?= $userId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= owner_access_e(
                $csrfToken
            ) ?>"
          >

          <input
            type="hidden"
            name="action"
            value="<?= $targetIsOwner
                ? 'demote_owner'
                : 'promote_owner'
            ?>"
          >


          <div class="owner-access-warning">

            <i
              class="fa-solid fa-triangle-exclamation"
              aria-hidden="true"
            ></i>

            <div>

              <strong>
                High-security action
              </strong>

              <p>

                <?php if (
                    $targetIsOwner
                ): ?>

                  This removes Owner authority from
                  @<?= owner_access_e(
                      $targetUsername
                  ) ?>.
                  The last remaining Owner can never be
                  demoted.

                <?php else: ?>

                  This gives
                  @<?= owner_access_e(
                      $targetUsername
                  ) ?>
                  unrestricted Owner-level Basecamp access.
                  MFA will be mandatory.

                <?php endif; ?>

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
              placeholder="<?= owner_access_e(
                  $targetUsername
              ) ?>"
              required
            >

            <p class="admin-field-help">
              Type
              <strong>
                <?= owner_access_e(
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
              Your 6-digit Owner authentication code
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
              Use a fresh code from your authenticator.
              Recovery codes cannot authorize Owner-role
              changes.
            </p>

          </div>


          <label class="admin-checkbox owner-access-acknowledgement">

            <input
              type="checkbox"
              name="acknowledge_owner_access"
              value="1"
              required
            >

            <span>

              <?php if (
                  $targetIsOwner
              ): ?>

                I understand that this removes Owner authority
                from this account.

              <?php else: ?>

                I understand that Owner access grants the
                highest level of authority in Llama Scout.

              <?php endif; ?>

            </span>

          </label>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="
                admin-button
                <?= $targetIsOwner
                    ? 'owner-access-danger-button'
                    : ''
                ?>
              "
            >

              <?php if (
                  $targetIsOwner
              ): ?>

                <i
                  class="fa-solid fa-user-minus"
                  aria-hidden="true"
                ></i>

                Remove Owner Access

              <?php else: ?>

                <i
                  class="fa-solid fa-crown"
                  aria-hidden="true"
                ></i>

                Promote to Owner

              <?php endif; ?>

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
