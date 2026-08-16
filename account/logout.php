<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

logout_user();

header(
    'Location: https://account.llamascout.com/login.php'
);

exit;
