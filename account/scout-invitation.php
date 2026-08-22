<?php

declare(strict_types=1);


/*
 * Legacy Scout invitation route.
 *
 * The active invitation workflow lives at:
 *
 *   /scout-invite.php
 *
 * Keep this file as a compatibility redirect so older
 * bookmarks, emails, or cached links do not break.
 */


$queryString =
    trim(
        (string) (
            $_SERVER[
                'QUERY_STRING'
            ]
            ?? ''
        )
    );


$location =
    '/scout-invite.php';


if (
    $queryString !== ''
) {

    $location .=
        '?'
        . $queryString;

}


header(
    'Location: '
    . $location,
    true,
    302
);


exit;
