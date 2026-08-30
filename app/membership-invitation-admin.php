<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   COMPLIMENTARY MEMBERSHIP INVITATION ADMIN
   app/membership-invitation-admin.php

   Owner-facing invitation creation, email delivery,
   revocation, replacement links, and history rendering.
   ========================================================= */


require_once __DIR__ . '/membership-invitations.php';
require_once __DIR__ . '/mail.php';


/* =========================================================
   DURATION OPTIONS
   ========================================================= */


function llama_complimentary_invite_duration_options(): array
{
    return [
        '30' => [
            'days' => 30,
            'label' => '1 Month',
        ],

        '90' => [
            'days' => 90,
            'label' => '3 Months',
        ],

        '180' => [
            'days' => 180,
            'label' => '6 Months',
        ],

        '365' => [
            'days' => 365,
            'label' => '1 Year',
        ],
    ];
}


/* =========================================================
   EMAIL
   ========================================================= */


function llama_send_complimentary_invitation_email(
    array $invitation,
    string $token
): bool {

    $email =
        llama_membership_invitation_normalize_email(
            (string) (
                $invitation['email']
                ?? ''
            )
        );

    $durationDays =
        (int) (
            $invitation['duration_days']
            ??
            $invitation['grant_duration_days']
            ??
            0
        );

    if ($durationDays < 1) {
        throw new RuntimeException(
            'Invitation duration is invalid.'
        );
    }

    $acceptUrl =
        'https://account.llamascout.com/complimentary-invite.php?token='
        . rawurlencode($token);

    $subject =
        'You have a complimentary Llama Scout membership';

    $text =
        "You have been invited to Llama Scout.\n\n"
        . "Your invitation includes {$durationDays} days of complimentary full membership access.\n\n"
        . "Open your private invitation link:\n\n"
        . $acceptUrl
        . "\n\n"
        . "If you do not already have a Llama Scout account, the invitation will guide you through creating one using this email address.\n\n"
        . "For security, the invitation is tied to this email address and expires after 14 days. Your complimentary membership period does not begin until you accept the invitation.\n\n"
        . "If you were not expecting this invitation, you can ignore this email.\n\n"
        . "Llama Scout\n"
        . "Know the place before you go.\n";

    $safeUrl =
        htmlspecialchars(
            $acceptUrl,
            ENT_QUOTES,
            'UTF-8'
        );

    $safeDays =
        htmlspecialchars(
            (string)$durationDays,
            ENT_QUOTES,
            'UTF-8'
        );

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Complimentary Llama Scout Membership</title>
</head>
<body>
  <h1>You have been invited to Llama Scout</h1>

  <p>
    Your invitation includes
    <strong>{$safeDays} days</strong>
    of complimentary full membership access.
  </p>

  <p>
    <a href="{$safeUrl}">
      Accept your complimentary membership
    </a>
  </p>

  <p>
    If you do not already have a Llama Scout account,
    the invitation will guide you through creating one using
    this email address.
  </p>

  <p>
    For security, this invitation is tied to the email address
    it was sent to and expires after 14 days. Your complimentary
    membership period does not begin until you accept it.
  </p>

  <p>
    If you were not expecting this invitation, you can ignore
    this email.
  </p>

  <p>
    Llama Scout<br>
    Know the place before you go.
  </p>
</body>
</html>
HTML;

    return
        send_llama_mail(
            $email,
            $subject,
            $text,
            $html
        );
}


/* =========================================================
   ADMIN CSRF
   ========================================================= */


function llama_complimentary_invite_admin_csrf(
    string $expected
): void {

    $submitted =
        (string) (
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $submitted === ''
        ||
        !hash_equals(
            $expected,
            $submitted
        )
    ) {
        throw new RuntimeException(
            'Your session could not be verified. Reload the page and try again.'
        );
    }
}


/* =========================================================
   PROCESS OWNER ACTION
   ========================================================= */


function llama_process_complimentary_invitation_admin(
    PDO $db,
    int $ownerId,
    string $csrfExpected
): ?string {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {
        return null;
    }

    $action =
        trim(
            (string) (
                $_POST['action']
                ?? ''
            )
        );

    if (
        !in_array(
            $action,
            [
                'create_complimentary_invitation',
                'revoke_complimentary_invitation',
                'replace_complimentary_invitation',
            ],
            true
        )
    ) {
        return null;
    }

    llama_complimentary_invite_admin_csrf(
        $csrfExpected
    );

    llama_ensure_membership_invitation_storage(
        $db
    );


    /* -----------------------------------------------------
       CREATE
       ----------------------------------------------------- */


    if (
        $action ===
        'create_complimentary_invitation'
    ) {

        $email =
            llama_membership_invitation_normalize_email(
                (string) (
                    $_POST['invite_email']
                    ?? ''
                )
            );

        $durationKey =
            trim(
                (string) (
                    $_POST['invite_duration']
                    ?? ''
                )
            );

        $options =
            llama_complimentary_invite_duration_options();

        if (
            !isset(
                $options[$durationKey]
            )
        ) {
            throw new InvalidArgumentException(
                'Choose a complimentary membership duration.'
            );
        }

        $durationDays =
            (int)
            $options[$durationKey]['days'];

        $reason =
            trim(
                (string) (
                    $_POST['invite_reason']
                    ?? ''
                )
            );

        $notes =
            trim(
                (string) (
                    $_POST['invite_notes']
                    ?? ''
                )
            );


        /*
         * Existing members should be managed through Users so
         * their membership history stays attached to the user
         * record rather than creating a pre-account invitation.
         */
        $existingStmt =
            $db->prepare(
                '
                SELECT
                    id,
                    username,
                    display_name

                FROM users

                WHERE LOWER(email) = ?

                LIMIT 1
                '
            );

        $existingStmt->execute([
            $email
        ]);

        $existing =
            $existingStmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($existing) {
            throw new RuntimeException(
                'That email already belongs to a Llama Scout account. Open the user in Basecamp and use Manage Membership instead.'
            );
        }


        $created =
            llama_create_complimentary_invitation(
                $db,
                $email,
                $durationDays,
                $reason !== ''
                    ? $reason
                    : null,
                $notes !== ''
                    ? $notes
                    : null,
                $ownerId
            );


        $sent =
            llama_send_complimentary_invitation_email(
                $created,
                (string)$created['token']
            );


        if (!$sent) {

            try {
                llama_revoke_complimentary_invitation(
                    $db,
                    (int)$created['id'],
                    $ownerId,
                    'Invitation email could not be delivered'
                );
            } catch (Throwable) {
                // Preserve the original mail failure.
            }

            throw new RuntimeException(
                'The invitation was created, but the email could not be sent. The invitation was disabled. Check the mail configuration and try again.'
            );
        }


        return
            'Complimentary membership invitation sent to '
            . $email
            . '.';
    }


    /* -----------------------------------------------------
       REVOKE
       ----------------------------------------------------- */


    if (
        $action ===
        'revoke_complimentary_invitation'
    ) {

        $invitationId =
            (int) (
                $_POST['invitation_id']
                ?? 0
            );

        if ($invitationId < 1) {
            throw new InvalidArgumentException(
                'Invalid invitation.'
            );
        }

        $reason =
            trim(
                (string) (
                    $_POST['revoke_reason']
                    ?? ''
                )
            );

        if ($reason === '') {
            $reason =
                'Revoked by Owner';
        }


        llama_revoke_complimentary_invitation(
            $db,
            $invitationId,
            $ownerId,
            $reason
        );


        return
            'Complimentary membership invitation revoked.';
    }


    /* -----------------------------------------------------
       REPLACE LINK

       Raw invitation tokens are never stored, so a pending
       invitation cannot literally resend the same secret.
       Create a new token and revoke the old one instead.
       ----------------------------------------------------- */


    $invitationId =
        (int) (
            $_POST['invitation_id']
            ?? 0
        );

    if ($invitationId < 1) {
        throw new InvalidArgumentException(
            'Invalid invitation.'
        );
    }


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM membership_invitations

            WHERE id = ?

            LIMIT 1
            '
        );

    $stmt->execute([
        $invitationId
    ]);

    $oldInvitation =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$oldInvitation) {
        throw new RuntimeException(
            'Invitation not found.'
        );
    }


    if (
        llama_complimentary_invitation_status(
            $oldInvitation
        )
        !==
        LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
    ) {
        throw new RuntimeException(
            'Only a pending invitation can receive a new link.'
        );
    }


    $created =
        llama_create_complimentary_invitation(
            $db,
            (string)$oldInvitation['email'],
            (int)$oldInvitation['grant_duration_days'],
            $oldInvitation['reason']
                ?: null,
            $oldInvitation['notes']
                ?: null,
            $ownerId
        );


    $sent =
        llama_send_complimentary_invitation_email(
            $created,
            (string)$created['token']
        );


    if (!$sent) {

        try {
            llama_revoke_complimentary_invitation(
                $db,
                (int)$created['id'],
                $ownerId,
                'Replacement invitation email could not be delivered'
            );
        } catch (Throwable) {
            // Preserve the original mail failure.
        }

        throw new RuntimeException(
            'A new invitation link was created, but the email could not be sent. The new invitation was disabled.'
        );
    }


    return
        'A new secure invitation link was sent to '
        . $oldInvitation['email']
        . '.';
}


/* =========================================================
   STATUS LABEL
   ========================================================= */


function llama_complimentary_invite_status_label(
    string $status
): string {

    return match ($status) {
        LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING =>
            'Pending',

        LLAMA_COMPLIMENTARY_INVITE_STATUS_ACCEPTED =>
            'Accepted',

        LLAMA_COMPLIMENTARY_INVITE_STATUS_REVOKED =>
            'Revoked',

        'expired' =>
            'Expired',

        default =>
            'Unknown',
    };
}


/* =========================================================
   RENDER OWNER SECTION
   ========================================================= */


function llama_render_complimentary_invitation_admin(
    PDO $db,
    string $csrfToken,
    DateTimeZone $ownerTimezone
): void {

    $invitations =
        llama_complimentary_invitations(
            $db,
            250
        );

    $durationOptions =
        llama_complimentary_invite_duration_options();

    ?>

  <!-- =====================================================
       COMPLIMENTARY MEMBERSHIP INVITATIONS
       ===================================================== -->

  <section class="membership-owner-section">

    <div class="membership-owner-section-header">

      <h2>
        Complimentary Invitations
      </h2>

      <p>
        Invite someone who does not have a Llama Scout account
        yet and reserve complimentary full membership access
        for their email address.
      </p>

    </div>


    <article class="membership-owner-card">

      <h3>
        Invite a Complimentary Member
      </h3>

      <p>
        Llama Scout sends a private, single-use invitation.
        The recipient has 14 days to claim it. Their
        complimentary membership begins when they accept,
        not when the invitation is sent.
      </p>


      <form method="post">

        <input
          type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars(
              $csrfToken,
              ENT_QUOTES,
              'UTF-8'
          ) ?>"
        >

        <input
          type="hidden"
          name="action"
          value="create_complimentary_invitation"
        >


        <div class="owner-form-grid">

          <div class="owner-field">

            <label for="invite_email">
              Email Address
            </label>

            <input
              id="invite_email"
              type="email"
              name="invite_email"
              autocomplete="email"
              placeholder="name@example.com"
              required
            >

          </div>


          <div class="owner-field">

            <label for="invite_duration">
              Complimentary Access
            </label>

            <select
              id="invite_duration"
              name="invite_duration"
              required
            >

              <?php foreach (
                  $durationOptions
                  as $key => $option
              ): ?>

                <option
                  value="<?= htmlspecialchars(
                      $key,
                      ENT_QUOTES,
                      'UTF-8'
                  ) ?>"
                  <?= $key === '30'
                      ? 'selected'
                      : ''
                  ?>
                >
                  <?= htmlspecialchars(
                      $option['label'],
                      ENT_QUOTES,
                      'UTF-8'
                  ) ?>
                </option>

              <?php endforeach; ?>

            </select>

          </div>


          <div class="owner-field">

            <label for="invite_reason">
              Reason
            </label>

            <input
              id="invite_reason"
              type="text"
              name="invite_reason"
              maxlength="255"
              placeholder="Beta tester, creator, press, partner..."
            >

          </div>


          <div
            class="
              owner-field
              owner-field--full
            "
          >

            <label for="invite_notes">
              Private Notes
            </label>

            <textarea
              id="invite_notes"
              name="invite_notes"
              maxlength="5000"
              placeholder="Optional internal notes about this invitation."
            ></textarea>

          </div>

        </div>


        <div class="owner-actions">

          <button
            type="submit"
            class="owner-button"
          >
            <i
              class="fa-solid fa-paper-plane"
              aria-hidden="true"
            ></i>

            Send Invitation
          </button>

        </div>

      </form>

    </article>


    <?php if (!$invitations): ?>

      <div class="owner-empty">
        No complimentary membership invitations have been sent
        yet.
      </div>

    <?php endif; ?>


    <?php foreach (
        $invitations as $invitation
    ): ?>

      <?php

      $status =
          llama_complimentary_invitation_status(
              $invitation
          );

      $acceptedName =
          trim(
              (string) (
                  $invitation['accepted_display_name']
                  ?:
                  $invitation['accepted_username']
                  ?:
                  ''
              )
          );

      $createdAt =
          new DateTimeImmutable(
              (string)$invitation['created_at'],
              new DateTimeZone('UTC')
          );

      $expiresAt =
          new DateTimeImmutable(
              (string)$invitation['expires_at'],
              new DateTimeZone('UTC')
          );

      ?>


      <article class="grant-card">

        <div class="grant-card-header">

          <div>

            <h3>
              <?= htmlspecialchars(
                  (string)$invitation['email'],
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>
            </h3>

            <div class="owner-small">
              <?= (int)$invitation['grant_duration_days'] ?>
              days complimentary access
            </div>

          </div>


          <span
            class="
              owner-pill
              <?= $status ===
                  LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
                      ? 'owner-pill--live'
                      : ''
              ?>
            "
          >
            <?= htmlspecialchars(
                llama_complimentary_invite_status_label(
                    $status
                ),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </span>

        </div>


        <div class="owner-meta">

          <div>
            <span>Sent</span>
            <strong>
              <?= htmlspecialchars(
                  $createdAt
                      ->setTimezone(
                          $ownerTimezone
                      )
                      ->format(
                          'M j, Y g:i A T'
                      ),
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>
            </strong>
          </div>

          <div>
            <span>Invitation Expires</span>
            <strong>
              <?= htmlspecialchars(
                  $expiresAt
                      ->setTimezone(
                          $ownerTimezone
                      )
                      ->format(
                          'M j, Y g:i A T'
                      ),
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>
            </strong>
          </div>

        </div>


        <?php if (
            !empty(
                $invitation['reason']
            )
        ): ?>

          <p>
            <strong>Reason:</strong>
            <?= htmlspecialchars(
                (string)$invitation['reason'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </p>

        <?php endif; ?>


        <?php if (
            !empty(
                $invitation['notes']
            )
        ): ?>

          <p class="owner-small">
            <?= nl2br(
                htmlspecialchars(
                    (string)$invitation['notes'],
                    ENT_QUOTES,
                    'UTF-8'
                )
            ) ?>
          </p>

        <?php endif; ?>


        <?php if (
            $status ===
            LLAMA_COMPLIMENTARY_INVITE_STATUS_ACCEPTED
        ): ?>

          <p class="owner-small">

            Accepted

            <?php if ($acceptedName !== ''): ?>
              by
              <?= htmlspecialchars(
                  $acceptedName,
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>
            <?php endif; ?>.

          </p>


          <?php if (
              !empty(
                  $invitation['accepted_by']
              )
          ): ?>

            <div class="owner-actions">

              <a
                class="
                  owner-button
                  owner-button--secondary
                "
                href="/user-membership.php?id=<?= (int)
                    $invitation['accepted_by']
                ?>"
              >
                Manage Membership
              </a>

            </div>

          <?php endif; ?>


        <?php elseif (
            $status ===
            LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
        ): ?>


          <div class="owner-actions">

            <form method="post">

              <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrfToken,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
              >

              <input
                type="hidden"
                name="action"
                value="replace_complimentary_invitation"
              >

              <input
                type="hidden"
                name="invitation_id"
                value="<?= (int)$invitation['id'] ?>"
              >

              <button
                type="submit"
                class="
                  owner-button
                  owner-button--secondary
                "
              >
                Send New Link
              </button>

            </form>


            <form method="post">

              <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrfToken,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
              >

              <input
                type="hidden"
                name="action"
                value="revoke_complimentary_invitation"
              >

              <input
                type="hidden"
                name="invitation_id"
                value="<?= (int)$invitation['id'] ?>"
              >

              <input
                type="hidden"
                name="revoke_reason"
                value="Revoked by Owner"
              >

              <button
                type="submit"
                class="
                  owner-button
                  owner-button--danger
                "
              >
                Revoke Invitation
              </button>

            </form>

          </div>


        <?php elseif (
            $status ===
            LLAMA_COMPLIMENTARY_INVITE_STATUS_REVOKED
            &&
            !empty(
                $invitation['revoke_reason']
            )
        ): ?>

          <p class="owner-small">
            <?= htmlspecialchars(
                (string)$invitation['revoke_reason'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </p>

        <?php endif; ?>


      </article>

    <?php endforeach; ?>


  </section>

    <?php
}
