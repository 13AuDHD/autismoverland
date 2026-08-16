<?php

declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();

echo json_encode([
    'logged_in' => $user !== null
]);
