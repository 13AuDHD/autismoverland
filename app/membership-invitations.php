<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   COMPLIMENTARY MEMBERSHIP INVITATIONS

   Secure invitation layer for complimentary memberships.

   Invitations are email-bound, single-use, expiring tokens.
   The raw token is never stored. Only its SHA-256 hash is
   persisted.

   The invitation itself does not grant membership access.
   Access is granted only after the invitation is accepted by
   the matching account.
   ========================================================= */


require_once __DIR__ . '/memberships.php';


const LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING =
    'pending';

const LLAMA_COMPLIMENTARY_INVITE_STATUS_ACCEPTED =
    'accepted';

const LLAMA_COMPLIMENTARY_INVITE_STATUS_REVOKED =
    'revoked';

const LLAMA_COMPLIMENTARY_INVITE_DEFAULT_EXPIRY_DAYS =
    14;


/* =========================================================
   STORAGE
   ========================================================= */


function llama_ensure_membership_invitation_storage(
    PDO $db
): void {

    if ($db->inTransaction()) {
        throw new RuntimeException(
            'Membership invitation storage cannot be initialized inside an active transaction.'
        );
    }

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS membership_invitations
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            email VARCHAR(254)
                NOT NULL,

            token_hash CHAR(64)
                NOT NULL,

            grant_duration_days INT UNSIGNED
                NOT NULL,

            reason VARCHAR(255)
                NULL,

            notes TEXT
                NULL,

            invited_by BIGINT UNSIGNED
                NULL,

            expires_at DATETIME
                NOT NULL,

            accepted_at DATETIME
                NULL,

            accepted_by BIGINT UNSIGNED
                NULL,

            grant_id BIGINT UNSIGNED
                NULL,

            revoked_at DATETIME
                NULL,

            revoked_by BIGINT UNSIGNED
                NULL,

            revoke_reason VARCHAR(255)
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_membership_invitation_token
                (token_hash),

            KEY idx_membership_invitation_email
                (email, expires_at, accepted_at, revoked_at),

            KEY idx_membership_invitation_invited_by
                (invited_by),

            KEY idx_membership_invitation_accepted_by
                (accepted_by),

            KEY idx_membership_invitation_grant
                (grant_id),

            CONSTRAINT fk_membership_invitation_invited_by
                FOREIGN KEY (invited_by)
                REFERENCES users(id)
                ON DELETE SET NULL,

            CONSTRAINT fk_membership_invitation_accepted_by
                FOREIGN KEY (accepted_by)
                REFERENCES users(id)
                ON DELETE SET NULL,

            CONSTRAINT fk_membership_invitation_grant
                FOREIGN KEY (grant_id)
                REFERENCES membership_grants(id)
                ON DELETE SET NULL,

            CONSTRAINT fk_membership_invitation_revoked_by
                FOREIGN KEY (revoked_by)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );
}


/* =========================================================
   NORMALIZATION
   ========================================================= */


function llama_membership_invitation_normalize_email(
    string $email
): string {

    $email = strtolower(
        trim($email)
    );

    if (
        $email === ''
        ||
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            'Enter a valid email address.'
        );
    }

    if (strlen($email) > 254) {
        throw new InvalidArgumentException(
            'Email address is too long.'
        );
    }

    return $email;
}


/* =========================================================
   CREATE
   ========================================================= */


function llama_create_complimentary_invitation(
    PDO $db,
    string $email,
    int $durationDays,
    ?string $reason,
    ?string $notes,
    int $invitedBy,
    int $expiresInDays =
        LLAMA_COMPLIMENTARY_INVITE_DEFAULT_EXPIRY_DAYS
): array {

    llama_ensure_membership_invitation_storage(
        $db
    );

    $email =
        llama_membership_invitation_normalize_email(
            $email
        );

    if (
        $durationDays < 1
        ||
        $durationDays > 3660
    ) {
        throw new InvalidArgumentException(
            'Complimentary membership duration must be between 1 day and 10 years.'
        );
    }

    if (
        $expiresInDays < 1
        ||
        $expiresInDays > 90
    ) {
        throw new InvalidArgumentException(
            'Invitation expiration must be between 1 and 90 days.'
        );
    }

    $reason =
        trim((string)$reason);

    $notes =
        trim((string)$notes);

    if (
        $reason !== ''
        &&
        mb_strlen($reason) > 255
    ) {
        throw new InvalidArgumentException(
            'Invitation reason is too long.'
        );
    }

    if (
        $notes !== ''
        &&
        mb_strlen($notes) > 5000
    ) {
        throw new InvalidArgumentException(
            'Private notes are too long.'
        );
    }

    /*
     * Revoke older still-pending invitations to the same email
     * before issuing a new one. This keeps one live invitation
     * per recipient and prevents confusing parallel tokens.
     */
    $db->beginTransaction();

    try {

        $revokeStmt =
            $db->prepare(
                '
                UPDATE membership_invitations

                SET
                    revoked_at = UTC_TIMESTAMP(),
                    revoked_by = ?,
                    revoke_reason =
                        \'Superseded by a newer invitation\'

                WHERE LOWER(email) = ?
                  AND accepted_at IS NULL
                  AND revoked_at IS NULL
                  AND expires_at > UTC_TIMESTAMP()
                '
            );

        $revokeStmt->execute([
            $invitedBy,
            $email,
        ]);

        $token =
            bin2hex(
                random_bytes(32)
            );

        $tokenHash =
            hash(
                'sha256',
                $token
            );

        $insertStmt =
            $db->prepare(
                '
                INSERT INTO membership_invitations
                (
                    email,
                    token_hash,
                    grant_duration_days,
                    reason,
                    notes,
                    invited_by,
                    expires_at
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    DATE_ADD(
                        UTC_TIMESTAMP(),
                        INTERVAL ? DAY
                    )
                )
                '
            );

        $insertStmt->execute([
            $email,
            $tokenHash,
            $durationDays,
            $reason !== '' ? $reason : null,
            $notes !== '' ? $notes : null,
            $invitedBy,
            $expiresInDays,
        ]);

        $invitationId =
            (int)$db->lastInsertId();

        llama_membership_audit(
            $db,
            $invitedBy,
            'complimentary_membership_invitation_created',
            'membership_invitation',
            $invitationId,
            [
                'email' =>
                    $email,

                'grant_duration_days' =>
                    $durationDays,

                'expires_in_days' =>
                    $expiresInDays,

                'reason' =>
                    $reason !== ''
                        ? $reason
                        : null,
            ]
        );

        $db->commit();

        return [
            'id' =>
                $invitationId,

            'email' =>
                $email,

            'token' =>
                $token,

            'duration_days' =>
                $durationDays,

            'expires_in_days' =>
                $expiresInDays,
        ];

    } catch (Throwable $exception) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}


/* =========================================================
   LOOKUP
   ========================================================= */


function llama_find_complimentary_invitation(
    PDO $db,
    string $token
): ?array {

    llama_ensure_membership_invitation_storage(
        $db
    );

    $token =
        trim($token);

    if (
        $token === ''
        ||
        !preg_match(
            '/^[a-f0-9]{64}$/i',
            $token
        )
    ) {
        return null;
    }

    $tokenHash =
        hash(
            'sha256',
            $token
        );

    $stmt =
        $db->prepare(
            '
            SELECT
                i.*,

                inviter.username
                    AS inviter_username,

                inviter.display_name
                    AS inviter_display_name

            FROM membership_invitations i

            LEFT JOIN users inviter
              ON inviter.id = i.invited_by

            WHERE i.token_hash = ?

            LIMIT 1
            '
        );

    $stmt->execute([
        $tokenHash
    ]);

    $invitation =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    return
        $invitation
            ?: null;
}


/* =========================================================
   STATUS
   ========================================================= */


function llama_complimentary_invitation_status(
    array $invitation
): string {

    if (
        !empty(
            $invitation['revoked_at']
        )
    ) {
        return
            LLAMA_COMPLIMENTARY_INVITE_STATUS_REVOKED;
    }

    if (
        !empty(
            $invitation['accepted_at']
        )
    ) {
        return
            LLAMA_COMPLIMENTARY_INVITE_STATUS_ACCEPTED;
    }

    $expiresAt =
        strtotime(
            (string)(
                $invitation['expires_at']
                ?? ''
            )
        );

    if (
        $expiresAt === false
        ||
        $expiresAt <= time()
    ) {
        return 'expired';
    }

    return
        LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING;
}


/* =========================================================
   ACCEPT
   ========================================================= */


function llama_accept_complimentary_invitation(
    PDO $db,
    string $token,
    int $userId
): int {

    llama_ensure_membership_invitation_storage(
        $db
    );

    if ($userId < 1) {
        throw new InvalidArgumentException(
            'Invalid user account.'
        );
    }

    $token =
        trim($token);

    if (
        !preg_match(
            '/^[a-f0-9]{64}$/i',
            $token
        )
    ) {
        throw new RuntimeException(
            'This invitation is invalid.'
        );
    }

    $tokenHash =
        hash(
            'sha256',
            $token
        );

    $db->beginTransaction();

    try {

        $inviteStmt =
            $db->prepare(
                '
                SELECT *

                FROM membership_invitations

                WHERE token_hash = ?

                LIMIT 1

                FOR UPDATE
                '
            );

        $inviteStmt->execute([
            $tokenHash
        ]);

        $invitation =
            $inviteStmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$invitation) {
            throw new RuntimeException(
                'This invitation is invalid.'
            );
        }

        $status =
            llama_complimentary_invitation_status(
                $invitation
            );

        if (
            $status !==
            LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
        ) {
            throw new RuntimeException(
                match ($status) {
                    LLAMA_COMPLIMENTARY_INVITE_STATUS_ACCEPTED =>
                        'This invitation has already been used.',

                    LLAMA_COMPLIMENTARY_INVITE_STATUS_REVOKED =>
                        'This invitation has been revoked.',

                    'expired' =>
                        'This invitation has expired.',

                    default =>
                        'This invitation is not available.',
                }
            );
        }

        $userStmt =
            $db->prepare(
                '
                SELECT
                    id,
                    email

                FROM users

                WHERE id = ?

                LIMIT 1

                FOR UPDATE
                '
            );

        $userStmt->execute([
            $userId
        ]);

        $user =
            $userStmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$user) {
            throw new RuntimeException(
                'User account not found.'
            );
        }

        $accountEmail =
            strtolower(
                trim(
                    (string)$user['email']
                )
            );

        $inviteEmail =
            strtolower(
                trim(
                    (string)$invitation['email']
                )
            );

        /*
         * The token alone is not enough. The logged-in or newly
         * registered account must use the exact email address
         * the Owner invited.
         */
        if (
            $accountEmail === ''
            ||
            !hash_equals(
                $inviteEmail,
                $accountEmail
            )
        ) {
            throw new RuntimeException(
                'This invitation was issued to a different email address.'
            );
        }

        /*
         * Do not stack a complimentary grant over another
         * currently-active complimentary grant.
         */
        $existingGrantStmt =
            $db->prepare(
                '
                SELECT id

                FROM membership_grants

                WHERE user_id = ?
                  AND grant_type = ?
                  AND revoked_at IS NULL
                  AND starts_at <= UTC_TIMESTAMP()
                  AND ends_at > UTC_TIMESTAMP()

                LIMIT 1

                FOR UPDATE
                '
            );

        $existingGrantStmt->execute([
            $userId,
            LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY,
        ]);

        if (
            $existingGrantStmt->fetchColumn()
        ) {
            throw new RuntimeException(
                'This account already has an active complimentary membership.'
            );
        }

        $durationDays =
            (int)$invitation[
                'grant_duration_days'
            ];

        $grantStmt =
            $db->prepare(
                '
                INSERT INTO membership_grants
                (
                    user_id,
                    grant_type,
                    starts_at,
                    ends_at,
                    reason,
                    notes,
                    granted_by
                )

                VALUES
                (
                    ?,
                    ?,
                    UTC_TIMESTAMP(),
                    DATE_ADD(
                        UTC_TIMESTAMP(),
                        INTERVAL ? DAY
                    ),
                    ?,
                    ?,
                    ?
                )
                '
            );

        $grantStmt->execute([
            $userId,
            LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY,
            $durationDays,
            $invitation['reason']
                ?: 'Complimentary invitation',
            $invitation['notes']
                ?: null,
            $invitation['invited_by']
                ?: null,
        ]);

        $grantId =
            (int)$db->lastInsertId();

        $acceptStmt =
            $db->prepare(
                '
                UPDATE membership_invitations

                SET
                    accepted_at = UTC_TIMESTAMP(),
                    accepted_by = ?,
                    grant_id = ?

                WHERE id = ?
                  AND accepted_at IS NULL
                  AND revoked_at IS NULL
                '
            );

        $acceptStmt->execute([
            $userId,
            $grantId,
            (int)$invitation['id'],
        ]);

        if (
            $acceptStmt->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'The invitation could not be accepted.'
            );
        }

        llama_membership_audit(
            $db,
            $userId,
            'complimentary_membership_invitation_accepted',
            'membership_invitation',
            (int)$invitation['id'],
            [
                'grant_id' =>
                    $grantId,

                'user_id' =>
                    $userId,

                'grant_duration_days' =>
                    $durationDays,
            ]
        );

        $db->commit();

        return $grantId;

    } catch (Throwable $exception) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}


/* =========================================================
   REVOKE PENDING INVITATION
   ========================================================= */


function llama_revoke_complimentary_invitation(
    PDO $db,
    int $invitationId,
    int $revokedBy,
    string $reason
): void {

    llama_ensure_membership_invitation_storage(
        $db
    );

    $reason =
        trim($reason);

    if ($reason === '') {
        throw new InvalidArgumentException(
            'Enter a revocation reason.'
        );
    }

    if (mb_strlen($reason) > 255) {
        throw new InvalidArgumentException(
            'Revocation reason is too long.'
        );
    }

    $db->beginTransaction();

    try {

        $stmt =
            $db->prepare(
                '
                UPDATE membership_invitations

                SET
                    revoked_at = UTC_TIMESTAMP(),
                    revoked_by = ?,
                    revoke_reason = ?

                WHERE id = ?
                  AND accepted_at IS NULL
                  AND revoked_at IS NULL
                '
            );

        $stmt->execute([
            $revokedBy,
            $reason,
            $invitationId,
        ]);

        if (
            $stmt->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'That invitation cannot be revoked.'
            );
        }

        llama_membership_audit(
            $db,
            $revokedBy,
            'complimentary_membership_invitation_revoked',
            'membership_invitation',
            $invitationId,
            [
                'reason' =>
                    $reason,
            ]
        );

        $db->commit();

    } catch (Throwable $exception) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}


/* =========================================================
   LIST
   ========================================================= */


function llama_complimentary_invitations(
    PDO $db,
    int $limit = 100
): array {

    llama_ensure_membership_invitation_storage(
        $db
    );

    $limit =
        max(
            1,
            min(
                500,
                $limit
            )
        );

    $sql =
        '
        SELECT
            i.*,

            inviter.username
                AS inviter_username,

            inviter.display_name
                AS inviter_display_name,

            accepted.username
                AS accepted_username,

            accepted.display_name
                AS accepted_display_name

        FROM membership_invitations i

        LEFT JOIN users inviter
          ON inviter.id = i.invited_by

        LEFT JOIN users accepted
          ON accepted.id = i.accepted_by

        ORDER BY
            i.created_at DESC,
            i.id DESC

        LIMIT '
        . $limit;

    $stmt =
        $db->query(
            $sql
        );

    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}
