<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';


/* =========================================================
   SESSION SETUP
   ========================================================= */

function start_llama_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('llamascout_session');

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
        $_SESSION['user_id'] ?? null;

    if (!$userId) {
        return null;
    }

    $stmt = db()->prepare(
        '
        SELECT
            id,
            email,
            display_name,
            status,
            email_verified_at,
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

    return $user;
}


/* =========================================================
   LOGIN STATUS
   ========================================================= */

function is_logged_in(): bool
{
    return current_user() !== null;
}


/* =========================================================
   LOGIN
   ========================================================= */

function attempt_login(
    string $login,
    string $password
): bool {

    $login =
        strtolower(
            trim($login)
        );

    $stmt = db()->prepare(
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
            $user['password_hash']
        )
    ) {
        return false;
    }

    if (
        $user['status'] === 'suspended' ||
        $user['status'] === 'disabled'
    ) {
        return false;
    }

    start_llama_session();

    session_regenerate_id(true);

    $_SESSION['user_id'] =
        (int) $user['id'];

    $_SESSION['logged_in_at'] =
        time();

    return true;
}

/* =========================================================
   LOGOUT
   ========================================================= */

function logout_user(): void
{
    start_llama_session();

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
    if (is_logged_in()) {
        return;
    }

    header(
        'Location: https://account.llamascout.com/login.php'
    );

    exit;
}


/* =========================================================
   USER ROLES
   ========================================================= */

function user_roles(
    ?int $userId = null
): array {

    if ($userId === null) {

        $user =
            current_user();

        if (!$user) {
            return [];
        }

        $userId =
            (int) $user['id'];
    }

    $stmt = db()->prepare(
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

    return array_column(
        $stmt->fetchAll(),
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

    return in_array(
        $role,
        user_roles($userId),
        true
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
        user_has_role($role)
    ) {
        return;
    }

    http_response_code(403);

    exit(
        'You do not have permission to access this page.'
    );
}
