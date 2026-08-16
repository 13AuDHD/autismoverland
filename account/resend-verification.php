<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';

require_login();

$user =
    current_user();


if (
    !empty(
        $user['email_verified_at']
    )
) {

    header(
        'Location: https://account.llamascout.com/verify-email.php'
    );

    exit;
}


/*
 * Invalidate previous unused links.
 */

$stmt =
    db()->prepare(
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


/*
 * Create a fresh secure token.
 */

$token =
    bin2hex(
        random_bytes(32)
    );

$tokenHash =
    hash(
        'sha256',
        $token
    );


$stmt =
    db()->prepare(
        '
        INSERT INTO email_verifications (
            user_id,
            token_hash,
            expires_at
        )
        VALUES (
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


send_verification_email(
    $user,
    $token
);


header(
    'Location: https://account.llamascout.com/verify-email.php?sent=1'
);

exit;
