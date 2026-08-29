<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   MULTI-FACTOR AUTHENTICATION
   app/mfa.php

   TOTP foundation for Owner and Admin accounts.

   Requirements:
   - PHP Sodium extension
   - Private config:
       'mfa' => [
           'encryption_key' => '64 hex characters'
       ]

   The encryption key MUST live outside the public repo.
   Generate once with:
       bin2hex(random_bytes(32))

   Never change the key after people enroll unless their
   existing TOTP secrets are migrated first.
   ========================================================= */


require_once
    __DIR__
    . '/database.php';


/* =========================================================
   CONSTANTS
   ========================================================= */


const LLAMA_MFA_ISSUER =
    'Llama Scout';


const LLAMA_MFA_PERIOD =
    30;


const LLAMA_MFA_DIGITS =
    6;


const LLAMA_MFA_WINDOW =
    1;


const LLAMA_MFA_RECOVERY_CODE_COUNT =
    10;


/* =========================================================
   DATABASE
   ========================================================= */


function llama_mfa_ensure_tables(
    ?PDO $db = null
): void {

    $db =
        $db
        ?: db();


    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS user_mfa
        (
            user_id BIGINT UNSIGNED NOT NULL,

            secret_ciphertext TEXT NULL,

            enabled_at DATETIME NULL,

            last_used_step BIGINT NULL,

            created_at DATETIME NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (user_id),

            CONSTRAINT fk_user_mfa_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes
        (
            id BIGINT UNSIGNED NOT NULL
                AUTO_INCREMENT,

            user_id BIGINT UNSIGNED NOT NULL,

            code_hash VARCHAR(255) NOT NULL,

            used_at DATETIME NULL,

            created_at DATETIME NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_user_mfa_recovery_user
                (user_id),

            CONSTRAINT fk_user_mfa_recovery_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );
}


/* =========================================================
   PRIVATE ENCRYPTION KEY
   ========================================================= */


function llama_mfa_encryption_key(): string {

    if (
        !function_exists(
            'llama_config'
        )
    ) {

        throw new RuntimeException(
            'Llama Scout configuration is not available.'
        );
    }


    $config =
        llama_config();


    $hex =
        trim(
            (string) (
                $config[
                    'mfa'
                ][
                    'encryption_key'
                ]
                ?? ''
            )
        );


    if (
        !preg_match(
            '/^[a-f0-9]{64}$/i',
            $hex
        )
    ) {

        throw new RuntimeException(
            'Llama Scout MFA encryption key is missing or invalid.'
        );
    }


    $key =
        hex2bin(
            $hex
        );


    if (
        !is_string(
            $key
        )
        ||
        strlen(
            $key
        )
        !== 32
    ) {

        throw new RuntimeException(
            'Llama Scout MFA encryption key could not be loaded.'
        );
    }


    return
        $key;
}


/* =========================================================
   SECRET ENCRYPTION
   ========================================================= */


function llama_mfa_encrypt_secret(
    string $secret
): string {

    $secret =
        trim(
            $secret
        );


    if (
        $secret === ''
    ) {

        throw new InvalidArgumentException(
            'MFA secret cannot be empty.'
        );
    }


    $key =
        llama_mfa_encryption_key();


    /*
     * Prefer Sodium when the server provides it.
     */

    if (
        function_exists(
            'sodium_crypto_secretbox'
        )
        &&
        defined(
            'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES'
        )
    ) {

        $nonce =
            random_bytes(
                SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            );


        $ciphertext =
            sodium_crypto_secretbox(
                $secret,
                $nonce,
                $key
            );


        return
            'sodium:'
            .
            base64_encode(
                $nonce
                .
                $ciphertext
            );
    }


    /*
     * Portable fallback for hosts without Sodium.
     */

    if (
        function_exists(
            'openssl_encrypt'
        )
        &&
        function_exists(
            'openssl_decrypt'
        )
    ) {

        $cipher =
            'aes-256-gcm';


        $ivLength =
            openssl_cipher_iv_length(
                $cipher
            );


        if (
            !is_int(
                $ivLength
            )
            ||
            $ivLength < 1
        ) {

            throw new RuntimeException(
                'OpenSSL could not determine a valid MFA encryption IV length.'
            );
        }


        $iv =
            random_bytes(
                $ivLength
            );


        $tag =
            '';


        $ciphertext =
            openssl_encrypt(
                $secret,
                $cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );


        if (
            !is_string(
                $ciphertext
            )
            ||
            $ciphertext === ''
            ||
            !is_string(
                $tag
            )
            ||
            $tag === ''
        ) {

            throw new RuntimeException(
                'MFA secret could not be encrypted.'
            );
        }


        return
            'openssl:'
            .
            base64_encode(
                $iv
                .
                $tag
                .
                $ciphertext
            );
    }


    throw new RuntimeException(
        'This server does not provide Sodium or OpenSSL encryption required for MFA.'
    );
}


function llama_mfa_decrypt_secret(
    string $encrypted
): string {

    $encrypted =
        trim(
            $encrypted
        );


    if (
        $encrypted === ''
    ) {

        throw new RuntimeException(
            'Stored MFA secret is invalid.'
        );
    }


    $key =
        llama_mfa_encryption_key();


    /*
     * Current Sodium format.
     */

    if (
        str_starts_with(
            $encrypted,
            'sodium:'
        )
    ) {

        if (
            !function_exists(
                'sodium_crypto_secretbox_open'
            )
            ||
            !defined(
                'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES'
            )
        ) {

            throw new RuntimeException(
                'This MFA secret requires PHP Sodium, but Sodium is not available on this server.'
            );
        }


        $decoded =
            base64_decode(
                substr(
                    $encrypted,
                    7
                ),
                true
            );


        if (
            !is_string(
                $decoded
            )
            ||
            strlen(
                $decoded
            )
            <=
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {

            throw new RuntimeException(
                'Stored MFA secret is invalid.'
            );
        }


        $nonce =
            substr(
                $decoded,
                0,
                SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            );


        $ciphertext =
            substr(
                $decoded,
                SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            );


        $plain =
            sodium_crypto_secretbox_open(
                $ciphertext,
                $nonce,
                $key
            );


        if (
            $plain === false
        ) {

            throw new RuntimeException(
                'Stored MFA secret could not be decrypted.'
            );
        }


        return
            $plain;
    }


    /*
     * OpenSSL AES-256-GCM format.
     */

    if (
        str_starts_with(
            $encrypted,
            'openssl:'
        )
    ) {

        if (
            !function_exists(
                'openssl_decrypt'
            )
        ) {

            throw new RuntimeException(
                'This MFA secret requires OpenSSL, but OpenSSL is not available on this server.'
            );
        }


        $cipher =
            'aes-256-gcm';


        $ivLength =
            openssl_cipher_iv_length(
                $cipher
            );


        $decoded =
            base64_decode(
                substr(
                    $encrypted,
                    8
                ),
                true
            );


        if (
            !is_int(
                $ivLength
            )
            ||
            $ivLength < 1
            ||
            !is_string(
                $decoded
            )
            ||
            strlen(
                $decoded
            )
            <=
            $ivLength + 16
        ) {

            throw new RuntimeException(
                'Stored MFA secret is invalid.'
            );
        }


        $iv =
            substr(
                $decoded,
                0,
                $ivLength
            );


        $tag =
            substr(
                $decoded,
                $ivLength,
                16
            );


        $ciphertext =
            substr(
                $decoded,
                $ivLength + 16
            );


        $plain =
            openssl_decrypt(
                $ciphertext,
                $cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );


        if (
            $plain === false
        ) {

            throw new RuntimeException(
                'Stored MFA secret could not be decrypted.'
            );
        }


        return
            $plain;
    }


    /*
     * Legacy pre-prefix Sodium format.
     *
     * This preserves compatibility if any MFA record was
     * successfully created before the storage format gained
     * an explicit encryption prefix.
     */

    if (
        function_exists(
            'sodium_crypto_secretbox_open'
        )
        &&
        defined(
            'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES'
        )
    ) {

        $decoded =
            base64_decode(
                $encrypted,
                true
            );


        if (
            is_string(
                $decoded
            )
            &&
            strlen(
                $decoded
            )
            >
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {

            $nonce =
                substr(
                    $decoded,
                    0,
                    SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                );


            $ciphertext =
                substr(
                    $decoded,
                    SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                );


            $plain =
                sodium_crypto_secretbox_open(
                    $ciphertext,
                    $nonce,
                    $key
                );


            if (
                $plain !== false
            ) {

                return
                    $plain;
            }
        }
    }


    throw new RuntimeException(
        'Stored MFA secret uses an unsupported encryption format.'
    );
}



/* =========================================================
   BASE32
   ========================================================= */


function llama_mfa_base32_encode(
    string $binary
): string {

    $alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';


    $bits =
        '';


    $length =
        strlen(
            $binary
        );


    for (
        $i = 0;
        $i < $length;
        $i++
    ) {

        $bits .=
            str_pad(
                decbin(
                    ord(
                        $binary[$i]
                    )
                ),
                8,
                '0',
                STR_PAD_LEFT
            );
    }


    $result =
        '';


    foreach (
        str_split(
            $bits,
            5
        )
        as
        $chunk
    ) {

        if (
            strlen(
                $chunk
            )
            < 5
        ) {

            $chunk =
                str_pad(
                    $chunk,
                    5,
                    '0',
                    STR_PAD_RIGHT
                );
        }


        $result .=
            $alphabet[
                bindec(
                    $chunk
                )
            ];
    }


    return
        $result;
}


function llama_mfa_base32_decode(
    string $encoded
): string {

    $alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';


    $encoded =
        strtoupper(
            preg_replace(
                '/[^A-Z2-7]/i',
                '',
                $encoded
            )
            ?? ''
        );


    if (
        $encoded === ''
    ) {

        return '';
    }


    $bits =
        '';


    $length =
        strlen(
            $encoded
        );


    for (
        $i = 0;
        $i < $length;
        $i++
    ) {

        $position =
            strpos(
                $alphabet,
                $encoded[$i]
            );


        if (
            $position === false
        ) {

            throw new InvalidArgumentException(
                'Invalid Base32 value.'
            );
        }


        $bits .=
            str_pad(
                decbin(
                    $position
                ),
                5,
                '0',
                STR_PAD_LEFT
            );
    }


    $binary =
        '';


    foreach (
        str_split(
            $bits,
            8
        )
        as
        $byte
    ) {

        if (
            strlen(
                $byte
            )
            < 8
        ) {

            break;
        }


        $binary .=
            chr(
                bindec(
                    $byte
                )
            );
    }


    return
        $binary;
}


/* =========================================================
   SECRET GENERATION
   ========================================================= */


function llama_mfa_generate_secret(): string {

    return
        llama_mfa_base32_encode(
            random_bytes(
                20
            )
        );
}


/* =========================================================
   TOTP
   ========================================================= */


function llama_mfa_hotp(
    string $secret,
    int $counter
): string {

    $binarySecret =
        llama_mfa_base32_decode(
            $secret
        );


    if (
        $binarySecret === ''
    ) {

        throw new InvalidArgumentException(
            'Invalid MFA secret.'
        );
    }


    $high =
        intdiv(
            $counter,
            0x100000000
        );


    $low =
        $counter
        %
        0x100000000;


    $counterBytes =
        pack(
            'N2',
            $high,
            $low
        );


    $hash =
        hash_hmac(
            'sha1',
            $counterBytes,
            $binarySecret,
            true
        );


    $offset =
        ord(
            $hash[
                strlen(
                    $hash
                )
                -
                1
            ]
        )
        &
        0x0f;


    $binary =
        (
            (
                ord(
                    $hash[$offset]
                )
                &
                0x7f
            )
            <<
            24
        )
        |
        (
            (
                ord(
                    $hash[$offset + 1]
                )
                &
                0xff
            )
            <<
            16
        )
        |
        (
            (
                ord(
                    $hash[$offset + 2]
                )
                &
                0xff
            )
            <<
            8
        )
        |
        (
            ord(
                $hash[$offset + 3]
            )
            &
            0xff
        );


    $otp =
        $binary
        %
        (
            10
            **
            LLAMA_MFA_DIGITS
        );


    return
        str_pad(
            (string)
            $otp,
            LLAMA_MFA_DIGITS,
            '0',
            STR_PAD_LEFT
        );
}


function llama_mfa_totp(
    string $secret,
    ?int $timestamp = null
): string {

    $timestamp =
        $timestamp
        ?? time();


    $counter =
        intdiv(
            $timestamp,
            LLAMA_MFA_PERIOD
        );


    return
        llama_mfa_hotp(
            $secret,
            $counter
        );
}


function llama_mfa_verify_totp(
    string $secret,
    string $code,
    ?int &$matchedStep = null
): bool {

    $code =
        preg_replace(
            '/\D+/',
            '',
            $code
        )
        ?? '';


    if (
        strlen(
            $code
        )
        !==
        LLAMA_MFA_DIGITS
    ) {

        return false;
    }


    $currentStep =
        intdiv(
            time(),
            LLAMA_MFA_PERIOD
        );


    for (
        $offset =
            -LLAMA_MFA_WINDOW;

        $offset <=
            LLAMA_MFA_WINDOW;

        $offset++
    ) {

        $step =
            $currentStep
            +
            $offset;


        $expected =
            llama_mfa_hotp(
                $secret,
                $step
            );


        if (
            hash_equals(
                $expected,
                $code
            )
        ) {

            $matchedStep =
                $step;


            return true;
        }
    }


    return false;
}


/* =========================================================
   ROLE REQUIREMENT
   ========================================================= */


function llama_mfa_role_requires_mfa(
    int $userId,
    ?PDO $db = null
): bool {

    if (
        $userId < 1
    ) {

        return false;
    }


    $db =
        $db
        ?: db();


    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id = ur.role_id

            WHERE ur.user_id = ?
              AND r.slug IN
              (
                  \'owner\',
                  \'admin\'
              )

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


/* =========================================================
   USER MFA STATE
   ========================================================= */


function llama_mfa_record(
    int $userId,
    ?PDO $db = null
): ?array {

    if (
        $userId < 1
    ) {

        return null;
    }


    $db =
        $db
        ?: db();


    llama_mfa_ensure_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                user_id,
                secret_ciphertext,
                enabled_at,
                last_used_step,
                created_at,
                updated_at

            FROM user_mfa

            WHERE user_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        is_array(
            $row
        )
            ? $row
            : null;
}


function llama_mfa_is_enabled(
    int $userId,
    ?PDO $db = null
): bool {

    $record =
        llama_mfa_record(
            $userId,
            $db
        );


    return
        is_array(
            $record
        )
        &&
        !empty(
            $record[
                'enabled_at'
            ]
        )
        &&
        !empty(
            $record[
                'secret_ciphertext'
            ]
        );
}


function llama_mfa_get_secret(
    int $userId,
    ?PDO $db = null
): ?string {

    $record =
        llama_mfa_record(
            $userId,
            $db
        );


    if (
        !is_array(
            $record
        )
        ||
        empty(
            $record[
                'secret_ciphertext'
            ]
        )
    ) {

        return null;
    }


    return
        llama_mfa_decrypt_secret(
            (string)
            $record[
                'secret_ciphertext'
            ]
        );
}


/* =========================================================
   ENROLLMENT
   ========================================================= */


function llama_mfa_begin_enrollment(
    int $userId,
    ?PDO $db = null
): string {

    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid user ID is required.'
        );
    }


    $db =
        $db
        ?: db();


    llama_mfa_ensure_tables(
        $db
    );


    $secret =
        llama_mfa_generate_secret();


    $encrypted =
        llama_mfa_encrypt_secret(
            $secret
        );


    $stmt =
        $db->prepare(
            '
            INSERT INTO user_mfa
            (
                user_id,
                secret_ciphertext,
                enabled_at,
                last_used_step
            )

            VALUES
            (
                ?,
                ?,
                NULL,
                NULL
            )

            ON DUPLICATE KEY UPDATE
                secret_ciphertext =
                    VALUES(secret_ciphertext),

                enabled_at =
                    NULL,

                last_used_step =
                    NULL
            '
        );


    $stmt->execute([
        $userId,
        $encrypted,
    ]);


    llama_mfa_delete_recovery_codes(
        $userId,
        $db
    );


    return
        $secret;
}


function llama_mfa_enable(
    int $userId,
    string $code,
    ?PDO $db = null
): array {

    $db =
        $db
        ?: db();


    $record =
        llama_mfa_record(
            $userId,
            $db
        );


    if (
        !is_array(
            $record
        )
        ||
        empty(
            $record[
                'secret_ciphertext'
            ]
        )
    ) {

        throw new RuntimeException(
            'MFA enrollment has not been started.'
        );
    }


    $secret =
        llama_mfa_decrypt_secret(
            (string)
            $record[
                'secret_ciphertext'
            ]
        );


    $matchedStep =
        null;


    if (
        !llama_mfa_verify_totp(
            $secret,
            $code,
            $matchedStep
        )
    ) {

        throw new RuntimeException(
            'That authentication code is not valid.'
        );
    }


    $db->beginTransaction();


    try {

        $stmt =
            $db->prepare(
                '
                UPDATE user_mfa

                SET
                    enabled_at =
                        CURRENT_TIMESTAMP,

                    last_used_step = ?

                WHERE user_id = ?
                '
            );


        $stmt->execute([
            $matchedStep,
            $userId,
        ]);


        $recoveryCodes =
            llama_mfa_replace_recovery_codes(
                $userId,
                $db
            );


        $db->commit();


        return
            $recoveryCodes;


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }
}


/* =========================================================
   TOTP AUTHENTICATION
   ========================================================= */


function llama_mfa_authenticate_totp(
    int $userId,
    string $code,
    ?PDO $db = null
): bool {

    $db =
        $db
        ?: db();


    $record =
        llama_mfa_record(
            $userId,
            $db
        );


    if (
        !is_array(
            $record
        )
        ||
        empty(
            $record[
                'enabled_at'
            ]
        )
        ||
        empty(
            $record[
                'secret_ciphertext'
            ]
        )
    ) {

        return false;
    }


    $secret =
        llama_mfa_decrypt_secret(
            (string)
            $record[
                'secret_ciphertext'
            ]
        );


    $matchedStep =
        null;


    if (
        !llama_mfa_verify_totp(
            $secret,
            $code,
            $matchedStep
        )
    ) {

        return false;
    }


    $lastUsedStep =
        isset(
            $record[
                'last_used_step'
            ]
        )
        &&
        $record[
            'last_used_step'
        ]
        !== null
            ? (int)
              $record[
                  'last_used_step'
              ]
            : null;


    /*
     * Do not accept the exact same TOTP time-step twice.
     */

    if (
        $lastUsedStep !== null
        &&
        $matchedStep !== null
        &&
        $matchedStep <=
        $lastUsedStep
    ) {

        return false;
    }


    $stmt =
        $db->prepare(
            '
            UPDATE user_mfa

            SET last_used_step = ?

            WHERE user_id = ?
            '
        );


    $stmt->execute([
        $matchedStep,
        $userId,
    ]);


    return true;
}


/* =========================================================
   RECOVERY CODES
   ========================================================= */


function llama_mfa_format_recovery_code(
    string $raw
): string {

    $raw =
        strtoupper(
            preg_replace(
                '/[^A-Z0-9]/',
                '',
                $raw
            )
            ?? ''
        );


    return
        substr(
            $raw,
            0,
            4
        )
        .
        '-'
        .
        substr(
            $raw,
            4,
            4
        )
        .
        '-'
        .
        substr(
            $raw,
            8,
            4
        );
}


function llama_mfa_generate_recovery_code(): string {

    $alphabet =
        'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';


    $code =
        '';


    $max =
        strlen(
            $alphabet
        )
        -
        1;


    for (
        $i = 0;
        $i < 12;
        $i++
    ) {

        $code .=
            $alphabet[
                random_int(
                    0,
                    $max
                )
            ];
    }


    return
        llama_mfa_format_recovery_code(
            $code
        );
}


function llama_mfa_normalize_recovery_code(
    string $code
): string {

    return
        strtoupper(
            preg_replace(
                '/[^A-Z0-9]/i',
                '',
                $code
            )
            ?? ''
        );
}


function llama_mfa_delete_recovery_codes(
    int $userId,
    ?PDO $db = null
): void {

    $db =
        $db
        ?: db();


    llama_mfa_ensure_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            DELETE FROM user_mfa_recovery_codes

            WHERE user_id = ?
            '
        );


    $stmt->execute([
        $userId
    ]);
}


function llama_mfa_replace_recovery_codes(
    int $userId,
    ?PDO $db = null
): array {

    $db =
        $db
        ?: db();


    llama_mfa_delete_recovery_codes(
        $userId,
        $db
    );


    $insert =
        $db->prepare(
            '
            INSERT INTO user_mfa_recovery_codes
            (
                user_id,
                code_hash
            )

            VALUES
            (
                ?,
                ?
            )
            '
        );


    $codes =
        [];


    for (
        $i = 0;
        $i <
            LLAMA_MFA_RECOVERY_CODE_COUNT;
        $i++
    ) {

        $code =
            llama_mfa_generate_recovery_code();


        $normalized =
            llama_mfa_normalize_recovery_code(
                $code
            );


        $hash =
            password_hash(
                $normalized,
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
                'Recovery code could not be secured.'
            );
        }


        $insert->execute([
            $userId,
            $hash,
        ]);


        $codes[] =
            $code;
    }


    return
        $codes;
}


function llama_mfa_recovery_code_count(
    int $userId,
    ?PDO $db = null
): int {

    $db =
        $db
        ?: db();


    llama_mfa_ensure_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM user_mfa_recovery_codes

            WHERE user_id = ?
              AND used_at IS NULL
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (int)
        $stmt->fetchColumn();
}


function llama_mfa_authenticate_recovery_code(
    int $userId,
    string $code,
    ?PDO $db = null
): bool {

    $db =
        $db
        ?: db();


    $normalized =
        llama_mfa_normalize_recovery_code(
            $code
        );


    if (
        strlen(
            $normalized
        )
        !== 12
    ) {

        return false;
    }


    llama_mfa_ensure_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                code_hash

            FROM user_mfa_recovery_codes

            WHERE user_id = ?
              AND used_at IS NULL

            ORDER BY id ASC
            '
        );


    $stmt->execute([
        $userId
    ]);


    foreach (
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        )
        as
        $row
    ) {

        if (
            password_verify(
                $normalized,
                (string)
                $row[
                    'code_hash'
                ]
            )
        ) {

            $consume =
                $db->prepare(
                    '
                    UPDATE user_mfa_recovery_codes

                    SET used_at =
                        CURRENT_TIMESTAMP

                    WHERE id = ?
                      AND used_at IS NULL
                    '
                );


            $consume->execute([
                $row[
                    'id'
                ]
            ]);


            return
                $consume->rowCount()
                === 1;
        }
    }


    return false;
}


/* =========================================================
   PROVISIONING URI
   ========================================================= */


function llama_mfa_provisioning_uri(
    string $secret,
    string $accountLabel
): string {

    $accountLabel =
        trim(
            $accountLabel
        );


    if (
        $accountLabel === ''
    ) {

        $accountLabel =
            'Llama Scout Account';
    }


    $label =
        rawurlencode(
            LLAMA_MFA_ISSUER
            .
            ':'
            .
            $accountLabel
        );


    $query =
        http_build_query(
            [
                'secret' =>
                    $secret,

                'issuer' =>
                    LLAMA_MFA_ISSUER,

                'algorithm' =>
                    'SHA1',

                'digits' =>
                    LLAMA_MFA_DIGITS,

                'period' =>
                    LLAMA_MFA_PERIOD,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );


    return
        'otpauth://totp/'
        .
        $label
        .
        '?'
        .
        $query;
}


/* =========================================================
   RESET / DISABLE MFA
   ========================================================= */


function llama_mfa_reset(
    int $userId,
    ?PDO $db = null
): void {

    $db =
        $db
        ?: db();


    llama_mfa_ensure_tables(
        $db
    );


    $db->beginTransaction();


    try {

        llama_mfa_delete_recovery_codes(
            $userId,
            $db
        );


        $stmt =
            $db->prepare(
                '
                DELETE FROM user_mfa

                WHERE user_id = ?
                '
            );


        $stmt->execute([
            $userId
        ]);


        $db->commit();


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }
}


/* =========================================================
   REMEMBER-TOKEN INVALIDATION
   Used when privileges change.
   ========================================================= */


function llama_mfa_invalidate_remember_tokens(
    int $userId,
    ?PDO $db = null
): void {

    $db =
        $db
        ?: db();


    $stmt =
        $db->prepare(
            '
            DELETE FROM user_remember_tokens

            WHERE user_id = ?
            '
        );


    $stmt->execute([
        $userId
    ]);
}


/* =========================================================
   SESSION MFA STATE
   ========================================================= */


function llama_mfa_clear_session_state(): void {

    if (
        session_status()
        !==
        PHP_SESSION_ACTIVE
    ) {

        return;
    }


    unset(
        $_SESSION[
            'mfa_pending_user_id'
        ],
        $_SESSION[
            'mfa_pending_remember'
        ],
        $_SESSION[
            'mfa_pending_return'
        ],
        $_SESSION[
            'mfa_verified_at'
        ],
        $_SESSION[
            'mfa_verified_user_id'
        ]
    );
}


function llama_mfa_begin_login_challenge(
    int $userId,
    bool $remember,
    ?string $returnUrl = null
): void {

    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid user ID is required.'
        );
    }


    $_SESSION[
        'mfa_pending_user_id'
    ] =
        $userId;


    $_SESSION[
        'mfa_pending_remember'
    ] =
        $remember;


    $_SESSION[
        'mfa_pending_return'
    ] =
        $returnUrl;


    unset(
        $_SESSION[
            'mfa_verified_at'
        ],
        $_SESSION[
            'mfa_verified_user_id'
        ]
    );
}


function llama_mfa_pending_user_id(): int {

    return
        (int) (
            $_SESSION[
                'mfa_pending_user_id'
            ]
            ?? 0
        );
}


function llama_mfa_mark_session_verified(
    int $userId
): void {

    $_SESSION[
        'mfa_verified_user_id'
    ] =
        $userId;


    $_SESSION[
        'mfa_verified_at'
    ] =
        time();


    unset(
        $_SESSION[
            'mfa_pending_user_id'
        ],
        $_SESSION[
            'mfa_pending_remember'
        ],
        $_SESSION[
            'mfa_pending_return'
        ]
    );
}


function llama_mfa_session_is_verified(
    int $userId
): bool {

    return
        $userId > 0
        &&
        (
            (int) (
                $_SESSION[
                    'mfa_verified_user_id'
                ]
                ?? 0
            )
        ) ===
        $userId
        &&
        !empty(
            $_SESSION[
                'mfa_verified_at'
            ]
        );
}
