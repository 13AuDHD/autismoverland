<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/scout-maintenance.php';


/* =========================================================
   REMEMBER ME
   ========================================================= */

const LLAMA_REMEMBER_DAYS = 30;
const LLAMA_REMEMBER_COOKIE = 'llamascout_remember';


function create_remember_token(
    int $userId
): void {

    if ($userId < 1) {
        return;
    }


    $selector =
        bin2hex(
            random_bytes(16)
        );


    $validator =
        bin2hex(
            random_bytes(32)
        );


    $tokenHash =
        password_hash(
            $validator,
            PASSWORD_DEFAULT
        );


    $expires =
        time()
        +
        (
            LLAMA_REMEMBER_DAYS
            * 86400
        );


    $expiresSql =
        date(
            'Y-m-d H:i:s',
            $expires
        );


    /*
     * Remove expired tokens for this user.
     */

    $cleanup =
        db()->prepare(
            '
            DELETE FROM user_remember_tokens

            WHERE user_id = ?
              AND expires_at < CURRENT_TIMESTAMP
            '
        );


    $cleanup->execute([
        $userId
    ]);


    /*
     * Store only the hash of the secret validator.
     */

    $stmt =
        db()->prepare(
            '
            INSERT INTO user_remember_tokens
            (
                user_id,
                selector,
                token_hash,
                expires_at
            )

            VALUES
            (
                ?, ?, ?, ?
            )
            '
        );


    $stmt->execute([
        $userId,
        $selector,
        $tokenHash,
        $expiresSql
    ]);


    /*
     * Cookie contains:
     *
     * selector:validator
     *
     * The raw validator exists only in the browser.
     */

    setcookie(
        LLAMA_REMEMBER_COOKIE,
        $selector . ':' . $validator,
        [
            'expires' =>
                $expires,

            'path' =>
                '/',

            'domain' =>
                '.llamascout.com',

            'secure' =>
                true,

            'httponly' =>
                true,

            'samesite' =>
                'Lax',
        ]
    );

}


/* =========================================================
   CLEAR REMEMBER ME
   ========================================================= */

function clear_remember_cookie(): void {

    if (
        !empty(
            $_COOKIE[
                LLAMA_REMEMBER_COOKIE
            ]
        )
    ) {

        $cookie =
            (string)
            $_COOKIE[
                LLAMA_REMEMBER_COOKIE
            ];


        $parts =
            explode(
                ':',
                $cookie,
                2
            );


        if (
            count($parts) === 2
        ) {

            $selector =
                $parts[0];


            if (
                $selector !== ''
            ) {

                $stmt =
                    db()->prepare(
                        '
                        DELETE FROM user_remember_tokens

                        WHERE selector = ?
                        '
                    );


                $stmt->execute([
                    $selector
                ]);

            }

        }

    }


    setcookie(
        LLAMA_REMEMBER_COOKIE,
        '',
        [
            'expires' =>
                time() - 3600,

            'path' =>
                '/',

            'domain' =>
                '.llamascout.com',

            'secure' =>
                true,

            'httponly' =>
                true,

            'samesite' =>
                'Lax',
        ]
    );

}


/* =========================================================
   REMEMBERED LOGIN
   ========================================================= */

function attempt_remembered_login(): bool {

    if (
        empty(
            $_COOKIE[
                LLAMA_REMEMBER_COOKIE
            ]
        )
    ) {

        return false;

    }


    $cookie =
        (string)
        $_COOKIE[
            LLAMA_REMEMBER_COOKIE
        ];


    $parts =
        explode(
            ':',
            $cookie,
            2
        );


    if (
        count($parts) !== 2
    ) {

        clear_remember_cookie();

        return false;

    }


    [
        $selector,
        $validator
    ] = $parts;


    if (
        $selector === ''
        ||
        $validator === ''
    ) {

        clear_remember_cookie();

        return false;

    }


    $stmt =
        db()->prepare(
            '
            SELECT
                rt.id,
                rt.user_id,
                rt.token_hash,
                rt.expires_at,
                u.status

            FROM user_remember_tokens rt

            INNER JOIN users u
                ON u.id = rt.user_id

            WHERE rt.selector = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $selector
    ]);


    $remember =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$remember) {

        clear_remember_cookie();

        return false;

    }


    if (
        strtotime(
            (string)
            $remember[
                'expires_at'
            ]
        ) <= time()
    ) {

        clear_remember_cookie();

        return false;

    }


    if (
        in_array(
            $remember[
                'status'
            ],
            [
                'suspended',
                'disabled'
            ],
            true
        )
    ) {

        clear_remember_cookie();

        return false;

    }


    if (
        !password_verify(
            $validator,
            $remember[
                'token_hash'
            ]
        )
    ) {

        clear_remember_cookie();

        return false;

    }


    start_llama_session();


    session_regenerate_id(
        true
    );


    $_SESSION[
        'user_id'
    ] =
        (int)
        $remember[
            'user_id'
        ];


    $_SESSION[
        'logged_in_at'
    ] =
        time();


    /*
     * Record activity.
     */

    $update =
        db()->prepare(
            '
            UPDATE user_remember_tokens

            SET last_used_at =
                CURRENT_TIMESTAMP

            WHERE id = ?
            '
        );


    $update->execute([
        $remember[
            'id'
        ]
    ]);


    return true;

}


/* =========================================================
   SESSION SETUP
   ========================================================= */

function start_llama_session(): void
{
    if (
        session_status()
        === PHP_SESSION_ACTIVE
    ) {

        return;

    }


    session_name(
        'llamascout_session'
    );


    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '.llamascout.com',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);


    session_start();
}


/* =========================================================
   CURRENT USER
   ========================================================= */

function current_user(): ?array
{
    start_llama_session();


    $userId =
        $_SESSION[
            'user_id'
        ]
        ?? null;


    /*
     * PHP session disappeared, but the user selected
     * Remember Me during login.
     */

    if (
        !$userId
        &&
        attempt_remembered_login()
    ) {

        $userId =
            $_SESSION[
                'user_id'
            ]
            ?? null;

    }


    if (!$userId) {

        return null;

    }


    $stmt =
        db()->prepare(
            '
            SELECT
                id,
                email,
                username,
                display_name,
                timezone,
                status,
                email_verified_at,
                stripe_customer_id,
                stripe_subscription_id,
                membership_status,
                membership_interval,
                membership_started_at,
                membership_ends_at,
                created_at

            FROM users

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $user =
        $stmt->fetch();


    if (!$user) {

        logout_user();

        return null;

    }


if (
    in_array(
        $user[
            'status'
        ],
        [
            'suspended',
            'disabled'
        ],
        true
    )
) {

    logout_user();

    return null;

}


/* =========================================================
   DAILY APPLICATION MAINTENANCE

   The first authenticated request after the maintenance
   interval expires runs Scout renewal maintenance.

   Failures are logged but must NEVER prevent someone from
   accessing their account.
   ========================================================= */

try {

    llama_run_scout_renewal_maintenance(
        db()
    );

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout daily maintenance bootstrap error: '
        .
        $exception
            ->getMessage()
    );

}


return $user;

}


/* =========================================================
   LOGIN STATUS
   ========================================================= */

function is_logged_in(): bool
{
    return
        current_user()
        !== null;
}


/* =========================================================
   LOGIN
   ========================================================= */

function attempt_login(
    string $login,
    string $password,
    bool $remember = false
): bool {

    $login =
        strtolower(
            trim($login)
        );


    $stmt =
        db()->prepare(
            '
            SELECT *

            FROM users

            WHERE LOWER(email) = ?
               OR LOWER(username) = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $login,
        $login
    ]);


    $user =
        $stmt->fetch();


    if (!$user) {

        return false;

    }


    if (
        !password_verify(
            $password,
            $user[
                'password_hash'
            ]
        )
    ) {

        return false;

    }


    if (
        $user[
            'status'
        ] === 'suspended'
        ||
        $user[
            'status'
        ] === 'disabled'
    ) {

        return false;

    }


    start_llama_session();


    session_regenerate_id(
        true
    );


    $_SESSION[
        'user_id'
    ] =
        (int)
        $user[
            'id'
        ];


    $_SESSION[
        'logged_in_at'
    ] =
        time();


    $loginStmt =
        db()->prepare(
            '
            UPDATE users

            SET
                last_login_at =
                    CURRENT_TIMESTAMP,

                dormancy_notice_sent_at =
                    NULL

            WHERE id = ?
            '
        );


    $loginStmt->execute([
        $user[
            'id'
        ]
    ]);


    if ($remember) {

        create_remember_token(
            (int)
            $user[
                'id'
            ]
        );

    }


    return true;
}


/* =========================================================
   LOGOUT
   ========================================================= */

function logout_user(): void
{
    start_llama_session();


    clear_remember_cookie();


    $_SESSION = [];


    if (
        ini_get(
            'session.use_cookies'
        )
    ) {

        $params =
            session_get_cookie_params();


        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );

    }


    session_destroy();
}


/* =========================================================
   REQUIRE LOGIN
   ========================================================= */

function require_login(): void
{
    if (
        is_logged_in()
    ) {

        return;

    }


    header(
        'Location: https://account.llamascout.com/login.php'
    );


    exit;
}


/* =========================================================
   EMAIL VERIFICATION
   ========================================================= */

function is_email_verified(
    ?array $user = null
): bool {

    if (
        $user === null
    ) {

        $user =
            current_user();

    }


    if (!$user) {

        return false;

    }


    return
        !empty(
            $user[
                'email_verified_at'
            ]
        );
}


function require_verified_email(): void
{
    require_login();


    $user =
        current_user();


    if (
        is_email_verified(
            $user
        )
    ) {

        return;

    }


    header(
        'Location: https://account.llamascout.com/verify-email.php'
    );


    exit;
}


/* =========================================================
   MEMBERSHIP ACCESS
   ========================================================= */

function membership_status(
    ?array $user = null
): string {

    if (
        $user === null
    ) {

        $user =
            current_user();

    }


    if (!$user) {

        return 'none';

    }


    return strtolower(
        trim(
            (string) (
                $user[
                    'membership_status'
                ]
                ?? 'none'
            )
        )
    );
}


/* =========================================================
   ACTIVE MEMBERSHIP
   ========================================================= */

function user_has_membership(
    ?array $user = null
): bool {

    $status =
        membership_status(
            $user
        );


    /*
     * past_due keeps access temporarily while Stripe
     * retries payment.
     */

    return in_array(
        $status,
        [
            'active',
            'trialing',
            'past_due',
            'complimentary',
        ],
        true
    );
}


/* =========================================================
   PAID MEMBERSHIP
   ========================================================= */

function user_has_paid_membership(
    ?array $user = null
): bool {

    $status =
        membership_status(
            $user
        );


    return in_array(
        $status,
        [
            'active',
            'trialing',
            'past_due',
        ],
        true
    );
}


/* =========================================================
   COMPLIMENTARY MEMBERSHIP
   ========================================================= */

function user_has_complimentary_membership(
    ?array $user = null
): bool {

    return
        membership_status(
            $user
        )
        === 'complimentary';
}


/* =========================================================
   REQUIRE MEMBERSHIP
   ========================================================= */

function require_membership(): void
{
    require_login();


    $user =
        current_user();


    /*
     * Owners and Admins always have full access
     * while those roles are assigned.
     */

if (
    user_has_role(
        'owner'
    )
    ||
    user_has_role(
        'admin'
    )
) {

    return;
}


if (
    user_has_role(
        'scout'
    )
) {

    $scoutStmt =
        db()->prepare(
            '
            SELECT 1

            FROM scout_profiles

            WHERE user_id = ?
              AND status = \'active\'

            LIMIT 1
            '
        );


    $scoutStmt->execute([
        (int) $user['id']
    ]);


    if (
        $scoutStmt->fetchColumn()
    ) {

        return;
    }
}


    if (
        user_has_membership(
            $user
        )
    ) {

        return;

    }


    header(
        'Location: https://account.llamascout.com/membership.php'
    );


    exit;
}

/* =========================================================
   USER ROLES
   ========================================================= */

function user_roles(
    ?int $userId = null
): array {

    if (
        $userId === null
    ) {

        $user =
            current_user();


        if (!$user) {

            return [];

        }


        $userId =
            (int)
            $user[
                'id'
            ];

    }


    $stmt =
        db()->prepare(
            '
            SELECT
                r.slug

            FROM roles r

            INNER JOIN user_roles ur
                ON ur.role_id = r.id

            WHERE ur.user_id = ?

            ORDER BY r.slug
            '
        );


    $stmt->execute([
        $userId
    ]);


    return array_column(
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ),
        'slug'
    );
}


/* =========================================================
   ROLE CHECK
   ========================================================= */

function user_has_role(
    string $role,
    ?int $userId = null
): bool {

    $roles =
        user_roles(
            $userId
        );


    /*
     * Exact role match.
     */

    if (
        in_array(
            $role,
            $roles,
            true
        )
    ) {

        return true;

    }


    /*
     * MASTER SCOUT LEGACY SLUG
     *
     * Older accounts may still use master_scout.
     * Treat it exactly like master-scout.
     */

    if (
        (
            $role === 'master-scout'
            &&
            in_array(
                'master_scout',
                $roles,
                true
            )
        )
        ||
        (
            $role === 'master_scout'
            &&
            in_array(
                'master-scout',
                $roles,
                true
            )
        )
    ) {

        return true;

    }

   
    /*
     * OWNER INHERITANCE
     *
     * Owner automatically satisfies anything requiring
     * the Admin role.
     *
     * An Admin does NOT satisfy anything requiring Owner.
     */

    if (
        $role === 'admin'
        &&
        in_array(
            'owner',
            $roles,
            true
        )
    ) {

        return true;

    }


   if (
    $role === 'scout'
    &&
    (
        in_array(
            'master-scout',
            $roles,
            true
        )
        ||
        in_array(
            'master_scout',
            $roles,
            true
        )
    )
) {

    return true;
}

   
    return false;
}


/* =========================================================
   OWNER CHECK
   ========================================================= */

function user_is_owner(
    ?int $userId = null
): bool {

    return user_has_role(
        'owner',
        $userId
    );
}


/* =========================================================
   ADMIN CHECK
   ========================================================= */

function user_is_admin(
    ?int $userId = null
): bool {

    /*
     * Because Owner inherits Admin,
     * this returns true for both Admins and Owners.
     */

    return user_has_role(
        'admin',
        $userId
    );
}


/* =========================================================
   REQUIRE ROLE
   ========================================================= */

function require_role(
    string $role
): void {

    require_login();


    if (
        user_has_role(
            $role
        )
    ) {

        return;

    }


    http_response_code(403);


    exit(
        'You do not have permission to access this page.'
    );
}
