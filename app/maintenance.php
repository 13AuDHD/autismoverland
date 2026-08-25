<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SITE MAINTENANCE MODE
   app/maintenance.php

   Central helper for:

   - reading maintenance state
   - turning maintenance mode on or off
   - storing expected return time
   - storing an optional custom message
   - allowing owner/admin bypass
   - protecting public pages during maintenance
   ========================================================= */


/* =========================================================
   DEFAULT SETTINGS
   ========================================================= */

const LLAMA_MAINTENANCE_DEFAULT_MESSAGE =
    'The llama is under the hood.';


/* =========================================================
   ENSURE SETTINGS TABLE EXISTS
   ========================================================= */

function llama_maintenance_ensure_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS site_settings
        (
            setting_key
                VARCHAR(100)
                NOT NULL,

            setting_value
                TEXT
                NULL,

            updated_at
                TIMESTAMP
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY
                (setting_key)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );
}


/* =========================================================
   READ SETTING
   ========================================================= */

function llama_site_setting(
    PDO $db,
    string $key,
    ?string $default = null
): ?string {

    llama_maintenance_ensure_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                setting_value

            FROM site_settings

            WHERE setting_key = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $key
    ]);


    $value =
        $stmt->fetchColumn();


    if (
        $value === false
    ) {

        return $default;
    }


    return
        $value !== null
            ? (string) $value
            : $default;
}


/* =========================================================
   WRITE SETTING
   ========================================================= */

function llama_set_site_setting(
    PDO $db,
    string $key,
    ?string $value
): void {

    llama_maintenance_ensure_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            INSERT INTO site_settings
            (
                setting_key,
                setting_value
            )

            VALUES
            (
                ?, ?
            )

            ON DUPLICATE KEY UPDATE
                setting_value =
                    VALUES(setting_value)
            '
        );


    $stmt->execute([
        $key,
        $value
    ]);
}


/* =========================================================
   MAINTENANCE STATE
   ========================================================= */

function llama_maintenance_settings(
    PDO $db
): array {

    $enabled =
        llama_site_setting(
            $db,
            'maintenance_enabled',
            '0'
        )
        ===
        '1';


    $returnAt =
        llama_site_setting(
            $db,
            'maintenance_return_at'
        );


    $message =
        trim(
            (string)
            llama_site_setting(
                $db,
                'maintenance_message',
                LLAMA_MAINTENANCE_DEFAULT_MESSAGE
            )
        );


    if (
        $message === ''
    ) {

        $message =
            LLAMA_MAINTENANCE_DEFAULT_MESSAGE;
    }


    return [
        'enabled' =>
            $enabled,

        'returnAt' =>
            $returnAt,

        'message' =>
            $message,
    ];
}


/* =========================================================
   SAVE MAINTENANCE STATE
   ========================================================= */

function llama_save_maintenance_settings(
    PDO $db,
    bool $enabled,
    ?string $returnAt,
    ?string $message
): void {

    $returnAt =
        trim(
            (string)
            $returnAt
        );


    $message =
        trim(
            (string)
            $message
        );


    llama_set_site_setting(
        $db,
        'maintenance_enabled',
        $enabled
            ? '1'
            : '0'
    );


    llama_set_site_setting(
        $db,
        'maintenance_return_at',
        $returnAt !== ''
            ? $returnAt
            : null
    );


    llama_set_site_setting(
        $db,
        'maintenance_message',
        $message !== ''
            ? $message
            : LLAMA_MAINTENANCE_DEFAULT_MESSAGE
    );
}


/* =========================================================
   ADMIN / OWNER BYPASS
   ========================================================= */

function llama_maintenance_user_can_bypass(
    PDO $db,
    ?array $user
): bool {

    if (
        !$user
        ||
        empty(
            $user['id']
        )
    ) {

        return false;
    }


    $userId =
        (int)
        $user['id'];


    /*
     * Check for owner or admin roles directly.
     *
     * This avoids depending on page-specific permission
     * helpers and keeps maintenance bypass self-contained.
     */

    $stmt =
        $db->prepare(
            '
            SELECT
                COUNT(*)

            FROM user_roles ur

            INNER JOIN roles r
                ON r.id = ur.role_id

            WHERE ur.user_id = ?

              AND r.slug IN
              (
                  \'owner\',
                  \'admin\'
              )
            '
        );


    try {

        $stmt->execute([
            $userId
        ]);


        return
            (int)
            $stmt->fetchColumn()
            > 0;


    } catch (
        Throwable $error
    ) {

        error_log(
            'Llama Scout maintenance bypass check failed: '
            .
            $error->getMessage()
        );


        return false;
    }
}


/* =========================================================
   REQUEST EXCLUSIONS
   ========================================================= */

function llama_maintenance_request_is_exempt(
    string $host,
    string $path
): bool {

    $host =
        strtolower(
            trim(
                preg_replace(
                    '/:\d+$/',
                    '',
                    $host
                )
                ?? $host
            )
        );


    $path =
        '/' .
        ltrim(
            $path,
            '/'
        );


    /*
     * The entire Admin Basecamp stays available.
     *
     * This is necessary so owners/admins can continue
     * working and can turn maintenance mode back off.
     */

    if (
        $host ===
        'admin.llamascout.com'
    ) {

        return true;
    }


    /*
     * Never intercept the maintenance page itself.
     */

    if (
        in_array(
            $host,
            [
                'llamascout.com',
                'www.llamascout.com',
            ],
            true
        )
        &&
        $path ===
        '/maintenance.php'
    ) {

        return true;
    }


    /*
     * Account authentication pages remain reachable so an
     * owner/admin can sign in during maintenance.
     *
     * Regular users may reach the login form, but once they
     * navigate elsewhere they will see maintenance mode.
     */

    if (
        $host ===
        'account.llamascout.com'
    ) {

        $accountExempt = [

            '/login.php',

            '/logout.php',

            '/forgot-password.php',

            '/reset-password.php',

            '/verify-email.php',

        ];


        if (
            in_array(
                $path,
                $accountExempt,
                true
            )
        ) {

            return true;
        }
    }


    /*
     * Static assets should never be intercepted.
     */

    $assetPrefixes = [

        '/css/',

        '/js/',

        '/images/',

        '/icons/',

    ];


    foreach (
        $assetPrefixes
        as $prefix
    ) {

        if (
            str_starts_with(
                $path,
                $prefix
            )
        ) {

            return true;
        }
    }


    return false;
}


/* =========================================================
   ENFORCE MAINTENANCE MODE
   ========================================================= */

function llama_enforce_maintenance_mode(
    PDO $db,
    ?array $user = null
): void {

    $settings =
        llama_maintenance_settings(
            $db
        );


    if (
        $settings['enabled']
        !== true
    ) {

        return;
    }


    $host =
        (string) (
            $_SERVER[
                'HTTP_HOST'
            ]
            ?? 'llamascout.com'
        );


    $path =
        parse_url(
            $_SERVER[
                'REQUEST_URI'
            ]
            ?? '/',
            PHP_URL_PATH
        );


    $path =
        is_string(
            $path
        )
            ? $path
            : '/';


    /*
     * Owners and admins can keep working normally.
     */

    if (
        llama_maintenance_user_can_bypass(
            $db,
            $user
        )
    ) {

        return;
    }


    /*
     * Certain requests must remain reachable.
     */

    if (
        llama_maintenance_request_is_exempt(
            $host,
            $path
        )
    ) {

        return;
    }


    /*
     * ALWAYS send maintenance traffic to the main site.
     *
     * Never use /maintenance.php here because that would
     * point to the current subdomain.
     */

    header(
        'Location: https://llamascout.com/maintenance.php',
        true,
        302
    );


    exit;
}
