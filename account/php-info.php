<?php

declare(strict_types=1);

header(
    'Content-Type: text/plain; charset=utf-8'
);

echo
    'PHP version: '
    .
    PHP_VERSION
    .
    "\n";

echo
    'upload_max_filesize: '
    .
    ini_get(
        'upload_max_filesize'
    )
    .
    "\n";

echo
    'post_max_size: '
    .
    ini_get(
        'post_max_size'
    )
    .
    "\n";

echo
    'memory_limit: '
    .
    ini_get(
        'memory_limit'
    )
    .
    "\n";

echo
    'user_ini.filename: '
    .
    ini_get(
        'user_ini.filename'
    )
    .
    "\n";

echo
    'Loaded php.ini: '
    .
    (
        php_ini_loaded_file()
        ?: 'none'
    )
    .
    "\n";

echo
    'Scanned ini files: '
    .
    (
        php_ini_scanned_files()
        ?: 'none'
    )
    .
    "\n";

echo
    'Document root: '
    .
    (
        $_SERVER['DOCUMENT_ROOT']
        ?? 'unknown'
    )
    .
    "\n";

echo
    'Current directory: '
    .
    __DIR__
    .
    "\n";

echo
    'user_ini.cache_ttl: '
    .
    ini_get(
        'user_ini.cache_ttl'
    )
    .
    "\n";




echo
    '.user.ini exists: '
    .
    (
        is_file(
            __DIR__
            . '/.user.ini'
        )
            ? 'yes'
            : 'no'
    )
    .
    "\n";

echo
    '.user.ini readable: '
    .
    (
        is_readable(
            __DIR__
            . '/.user.ini'
        )
            ? 'yes'
            : 'no'
    )
    .
    "\n";

echo
    '.user.ini contents: '
    .
    "\n"
    .
    (
        is_readable(
            __DIR__
            . '/.user.ini'
        )
            ? file_get_contents(
                __DIR__
                . '/.user.ini'
            )
            : 'unavailable'
    )
    .
    "\n";
