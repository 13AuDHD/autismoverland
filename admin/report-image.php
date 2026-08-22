<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_role(
    'admin'
);


$user =
    current_user();


$imageId =
    (int) (
        $_GET[
            'id'
        ]
        ?? 0
    );


if (
    $imageId < 1
) {

    http_response_code(
        400
    );

    exit;
}


$db =
    db();


$stmt =
    $db->prepare(
        '
        SELECT
            pri.id,
            pri.report_id,
            pri.file_path,
            pri.original_name,
            pri.mime_type,
            pri.file_size

        FROM place_report_images pri

        INNER JOIN place_reports pr
          ON pr.id =
             pri.report_id

        WHERE pri.id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $imageId
]);


$image =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$image
) {

    http_response_code(
        404
    );

    exit;
}


/* =========================================================
   VALIDATE STORAGE PATH
   ========================================================= */

$relativePath =
    '/'
    .
    ltrim(
        (string)
        $image[
            'file_path'
        ],
        '/'
    );


$allowedPrefix =
    '/uploads/place-reports/';


if (
    !str_starts_with(
        $relativePath,
        $allowedPrefix
    )
) {

    error_log(
        'Rejected invalid report evidence path for image #'
        .
        $imageId
    );


    http_response_code(
        404
    );

    exit;
}


$storageRoot =
    realpath(
        dirname(__DIR__)
        .
        '/uploads/place-reports'
    );


$filePath =
    realpath(
        dirname(__DIR__)
        .
        $relativePath
    );


if (
    $storageRoot ===
    false
    ||
    $filePath ===
    false
    ||
    !str_starts_with(
        $filePath,
        $storageRoot
        .
        DIRECTORY_SEPARATOR
    )
    ||
    !is_file(
        $filePath
    )
) {

    http_response_code(
        404
    );

    exit;
}


/* =========================================================
   CONTENT TYPE
   ========================================================= */

$extension =
    strtolower(
        pathinfo(
            $filePath,
            PATHINFO_EXTENSION
        )
    );


$contentType =
    match (
        $extension
    ) {

        'jpg',
        'jpeg' =>
            'image/jpeg',

        'png' =>
            'image/png',

        'webp' =>
            'image/webp',

        'avif' =>
            'image/avif',

        'heic' =>
            'image/heic',

        'heif' =>
            'image/heif',

        default =>
            null
    };


if (
    $contentType ===
    null
) {

    http_response_code(
        415
    );

    exit;
}


/* =========================================================
   SAFE FILE NAME
   ========================================================= */

$fileName =
    basename(
        trim(
            (string) (
                $image[
                    'original_name'
                ]
                ?? ''
            )
        )
    );


if (
    $fileName === ''
) {

    $fileName =
        'report-evidence.'
        .
        $extension;
}


/* =========================================================
   PRIVATE RESPONSE
   ========================================================= */

header(
    'Content-Type: '
    .
    $contentType
);


header(
    'Content-Length: '
    .
    filesize(
        $filePath
    )
);


header(
    'Cache-Control: private, no-store, max-age=0'
);


header(
    'Pragma: no-cache'
);


header(
    'X-Content-Type-Options: nosniff'
);


header(
    'Content-Disposition: inline; filename="'
    .
    str_replace(
        [
            '"',
            "\r",
            "\n"
        ],
        '',
        $fileName
    )
    .
    '"'
);


readfile(
    $filePath
);


exit;
