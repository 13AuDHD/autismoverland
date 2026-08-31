<?php

declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/places.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/weather.php';
require_once __DIR__ . '/saved-places.php';
require_once __DIR__ . '/photo-upload.php';
require_once __DIR__ . '/photo-staging.php';
require_once __DIR__ . '/community-profiles.php';
require_once __DIR__ . '/contributor-attribution.php';
require_once __DIR__ . '/profile-images.php';
require_once __DIR__ . '/shop-images.php';
require_once __DIR__ . '/place-reports.php';
require_once __DIR__ . '/community-contributions.php';
require_once __DIR__ . '/moderation.php';

start_llama_session();
