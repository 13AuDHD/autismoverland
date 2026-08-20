<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


/* =========================================================
   ACCESS
   ========================================================= */

require_verified_email();


start_llama_session();


$user =
    current_user();


/* =========================================================
   RESPONSE
   ========================================================= */

header(
    'Content-Type: application/json; charset=utf-8'
);


function respond(
    int $status,
    array $data
): never {

    http_response_code(
        $status
    );


    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    );


    exit;
}


/* =========================================================
   METHOD
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] !== 'POST'
) {

    respond(
        405,
        [
            'success' =>
                false,

            'message' =>
                'Photo uploads must use POST.'
        ]
    );

}


/* =========================================================
   CSRF
   ========================================================= */

$sessionToken =
    $_SESSION[
        'scout_place_csrf'
    ]
    ?? '';


$submittedToken =
    $_POST[
        'csrf_token'
    ]
    ?? '';


if (
    !is_string(
        $sessionToken
    )
    ||
    $sessionToken === ''
    ||
    !is_string(
        $submittedToken
    )
    ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {

    respond(
        403,
        [
            'success' =>
                false,

            'message' =>
                'Your session could not be verified. Reload the page and try again.'
        ]
    );

}


/* =========================================================
   SETTINGS
   ========================================================= */

$maxPhotos =
    5;


$maxBytesPerPhoto =
    15
    *
    1024
    *
    1024;


$maxDimension =
    2400;


$jpegQuality =
    84;


/* =========================================================
   UPLOAD PRESENT?
   ========================================================= */

if (
    !isset(
        $_FILES[
            'photos'
        ]
    )
    ||
    !is_array(
        $_FILES[
            'photos'
        ][
            'name'
        ]
        ?? null
    )
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Choose at least one photo.'
        ]
    );

}


/* =========================================================
   NORMALIZE FILE LIST
   ========================================================= */

$uploads =
    [];


$count =
    count(
        $_FILES[
            'photos'
        ][
            'name'
        ]
    );


for (
    $index = 0;
    $index < $count;
    $index++
) {

    $error =
        $_FILES[
            'photos'
        ][
            'error'
        ][
            $index
        ]
        ??
        UPLOAD_ERR_NO_FILE;


    if (
        $error ===
        UPLOAD_ERR_NO_FILE
    ) {

        continue;

    }


    $uploads[] = [

        'name' =>
            (string) (
                $_FILES[
                    'photos'
                ][
                    'name'
                ][
                    $index
                ]
                ?? ''
            ),

        'tmp_name' =>
            (string) (
                $_FILES[
                    'photos'
                ][
                    'tmp_name'
                ][
                    $index
                ]
                ?? ''
            ),

        'size' =>
            (int) (
                $_FILES[
                    'photos'
                ][
                    'size'
                ][
                    $index
                ]
                ?? 0
            ),

        'error' =>
            (int)
            $error

    ];

}


/* =========================================================
   NUMBER OF PHOTOS
   ========================================================= */

if (
    !$uploads
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Choose at least one photo.'
        ]
    );

}


if (
    count(
        $uploads
    ) > $maxPhotos
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'You can upload up to 5 photos at a time.'
        ]
    );

}


/* =========================================================
   IMAGE TYPE DETECTION
   ========================================================= */

function detect_uploaded_image(
    string $path
): ?string {

    if (
        !is_file(
            $path
        )
    ) {

        return null;

    }


    /*
     * First try finfo.
     */

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );


    $mime =
        $finfo->file(
            $path
        );


    if (
        is_string(
            $mime
        )
    ) {

        $normalized =
            strtolower(
                trim(
                    $mime
                )
            );


        $supported = [

            'image/jpeg' =>
                'jpeg',

            'image/jpg' =>
                'jpeg',

            'image/png' =>
                'png',

            'image/webp' =>
                'webp',

            'image/heic' =>
                'heic',

            'image/heif' =>
                'heif',

            'image/heic-sequence' =>
                'heic',

            'image/heif-sequence' =>
                'heif',

            'image/avif' =>
                'avif'

        ];


        if (
            isset(
                $supported[
                    $normalized
                ]
            )
        ) {

            return
                $supported[
                    $normalized
                ];

        }

    }


    /*
     * Apple HEIC/HEIF files are sometimes reported as
     * application/octet-stream.
     *
     * Detect the ISO Base Media File Format brand.
     */

    $handle =
        @fopen(
            $path,
            'rb'
        );


    if (
        !$handle
    ) {

        return null;

    }


    $header =
        fread(
            $handle,
            64
        );


    fclose(
        $handle
    );


    if (
        !is_string(
            $header
        )
        ||
        strlen(
            $header
        ) < 12
    ) {

        return null;

    }


    /*
     * JPEG
     */

    if (
        substr(
            $header,
            0,
            3
        ) ===
        "\xFF\xD8\xFF"
    ) {

        return 'jpeg';

    }


    /*
     * PNG
     */

    if (
        substr(
            $header,
            0,
            8
        ) ===
        "\x89PNG\x0D\x0A\x1A\x0A"
    ) {

        return 'png';

    }


    /*
     * WebP
     */

    if (
        substr(
            $header,
            0,
            4
        ) === 'RIFF'
        &&
        substr(
            $header,
            8,
            4
        ) === 'WEBP'
    ) {

        return 'webp';

    }


    /*
     * HEIC / HEIF / AVIF
     */

    $ftyp =
        strpos(
            $header,
            'ftyp'
        );


    if (
        $ftyp !== false
        &&
        strlen(
            $header
        ) >=
        $ftyp + 8
    ) {

        $brand =
            strtolower(
                substr(
                    $header,
                    $ftyp + 4,
                    4
                )
            );


        if (
            in_array(
                $brand,
                [
                    'heic',
                    'heix',
                    'hevc',
                    'hevx',
                    'heim',
                    'heis',
                    'mif1',
                    'msf1'
                ],
                true
            )
        ) {

            return 'heic';

        }


        if (
            in_array(
                $brand,
                [
                    'avif',
                    'avis'
                ],
                true
            )
        ) {

            return 'avif';

        }

    }


    return null;
}


/* =========================================================
   IMAGICK CAPABILITY
   ========================================================= */

function imagick_can_read(
    string $format
): bool {

    if (
        !class_exists(
            'Imagick'
        )
    ) {

        return false;

    }


    try {

        $queryFormat =
            match (
                $format
            ) {

                'jpeg' =>
                    'JPEG',

                'png' =>
                    'PNG',

                'webp' =>
                    'WEBP',

                'heic',
                'heif' =>
                    'HEIC',

                'avif' =>
                    'AVIF',

                default =>
                    strtoupper(
                        $format
                    )

            };


        $formats =
            Imagick::queryFormats(
                $queryFormat
            );


        return
            !empty(
                $formats
            );


    } catch (
        Throwable
    ) {

        return false;

    }

}


/* =========================================================
   EXIF ORIENTATION FOR GD FALLBACK
   ========================================================= */

function jpeg_orientation(
    string $path
): int {

    if (
        !function_exists(
            'exif_read_data'
        )
    ) {

        return 1;

    }


    try {

        $exif =
            @exif_read_data(
                $path
            );


        if (
            !is_array(
                $exif
            )
        ) {

            return 1;

        }


        return
            (int) (
                $exif[
                    'Orientation'
                ]
                ?? 1
            );


    } catch (
        Throwable
    ) {

        return 1;

    }

}


/* =========================================================
   RESIZE CALCULATION
   ========================================================= */

function resized_dimensions(
    int $width,
    int $height,
    int $maxDimension
): array {

    if (
        $width < 1
        ||
        $height < 1
    ) {

        return [
            0,
            0
        ];

    }


    if (
        $width <= $maxDimension
        &&
        $height <= $maxDimension
    ) {

        return [
            $width,
            $height
        ];

    }


    $ratio =
        min(
            $maxDimension / $width,
            $maxDimension / $height
        );


    return [

        max(
            1,
            (int)
            round(
                $width
                *
                $ratio
            )
        ),

        max(
            1,
            (int)
            round(
                $height
                *
                $ratio
            )
        )

    ];

}


/* =========================================================
   PROCESS WITH IMAGICK

   Reading and writing a brand-new JPEG strips the original
   image container and its EXIF/GPS metadata.
   ========================================================= */

function process_with_imagick(
    string $source,
    string $destination,
    int $maxDimension,
    int $quality
): array {

    $image =
        new Imagick();


    try {

        $image->readImage(
            $source
        );


        /*
         * HEIC/AVIF can technically contain multiple frames.
         * We only want the first still image.
         */

        if (
            $image->getNumberImages()
            > 1
        ) {

            $image->setIteratorIndex(
                0
            );

        }


        /*
         * Apply camera orientation before stripping metadata.
         */

        if (
            method_exists(
                $image,
                'autoOrient'
            )
        ) {

            $image->autoOrient();

        } elseif (
            method_exists(
                $image,
                'autoOrientImage'
            )
        ) {

            $image->autoOrientImage();

        }


        /*
         * Remove profiles, EXIF, comments, GPS and other
         * attached metadata.
         */

        $image->stripImage();


        $width =
            $image->getImageWidth();


        $height =
            $image->getImageHeight();


        [
            $newWidth,
            $newHeight
        ] =
            resized_dimensions(
                $width,
                $height,
                $maxDimension
            );


        if (
            $newWidth !== $width
            ||
            $newHeight !== $height
        ) {

            $image->thumbnailImage(
                $newWidth,
                $newHeight,
                true,
                true
            );

        }


        /*
         * JPEG cannot preserve transparency.
         * Flatten transparency onto a neutral white canvas.
         */

        if (
            method_exists(
                $image,
                'setImageBackgroundColor'
            )
        ) {

            $image->setImageBackgroundColor(
                'white'
            );

        }


        if (
            method_exists(
                $image,
                'mergeImageLayers'
            )
        ) {

            try {

                $flattened =
                    $image->mergeImageLayers(
                        Imagick::LAYERMETHOD_FLATTEN
                    );


                if (
                    $flattened instanceof Imagick
                ) {

                    $image->clear();

                    $image =
                        $flattened;

                }

            } catch (
                Throwable
            ) {

                /*
                 * Not all builds need or support flattening.
                 */

            }

        }


        $image->setImageFormat(
            'jpeg'
        );


        $image->setImageCompression(
            Imagick::COMPRESSION_JPEG
        );


        $image->setImageCompressionQuality(
            $quality
        );


        /*
         * Strip again after conversion so the finished JPEG
         * contains no inherited profiles.
         */

        $image->stripImage();


        if (
            !$image->writeImage(
                $destination
            )
        ) {

            throw new RuntimeException(
                'The processed photo could not be saved.'
            );

        }


        $finalWidth =
            $image->getImageWidth();


        $finalHeight =
            $image->getImageHeight();


        return [

            'width' =>
                $finalWidth,

            'height' =>
                $finalHeight

        ];


    } finally {

        $image->clear();

        $image->destroy();

    }

}


/* =========================================================
   PROCESS WITH GD

   Used when Imagick is unavailable for JPEG/PNG/WebP.
   Re-encoding into a fresh JPEG also removes EXIF/GPS.
   ========================================================= */

function process_with_gd(
    string $source,
    string $format,
    string $destination,
    int $maxDimension,
    int $quality
): array {

    if (
        !extension_loaded(
            'gd'
        )
    ) {

        throw new RuntimeException(
            'The server does not currently have an image processor available.'
        );

    }


    $image =
        match (
            $format
        ) {

            'jpeg' =>
                function_exists(
                    'imagecreatefromjpeg'
                )
                    ? @imagecreatefromjpeg(
                        $source
                    )
                    : false,

            'png' =>
                function_exists(
                    'imagecreatefrompng'
                )
                    ? @imagecreatefrompng(
                        $source
                    )
                    : false,

            'webp' =>
                function_exists(
                    'imagecreatefromwebp'
                )
                    ? @imagecreatefromwebp(
                        $source
                    )
                    : false,

            default =>
                false

        };


    if (
        !$image
    ) {

        throw new RuntimeException(
            'This image format cannot be decoded by the server.'
        );

    }


    try {

        /*
         * Honor JPEG camera orientation before writing the
         * clean replacement image.
         */

        if (
            $format === 'jpeg'
        ) {

            $orientation =
                jpeg_orientation(
                    $source
                );


            $rotated =
                null;


            switch (
                $orientation
            ) {

                case 3:

                    $rotated =
                        imagerotate(
                            $image,
                            180,
                            0
                        );

                    break;


                case 6:

                    $rotated =
                        imagerotate(
                            $image,
                            -90,
                            0
                        );

                    break;


                case 8:

                    $rotated =
                        imagerotate(
                            $image,
                            90,
                            0
                        );

                    break;

            }


            if (
                $rotated
            ) {

                imagedestroy(
                    $image
                );


                $image =
                    $rotated;

            }

        }


        $width =
            imagesx(
                $image
            );


        $height =
            imagesy(
                $image
            );


        [
            $newWidth,
            $newHeight
        ] =
            resized_dimensions(
                $width,
                $height,
                $maxDimension
            );


        /*
         * Always create a brand-new true-color image.
         *
         * This is intentional because it prevents metadata
         * from being copied into the saved JPEG.
         */

        $output =
            imagecreatetruecolor(
                $newWidth,
                $newHeight
            );


        if (
            !$output
        ) {

            throw new RuntimeException(
                'The photo could not be resized.'
            );

        }


        /*
         * JPEG has no alpha channel.
         */

        $white =
            imagecolorallocate(
                $output,
                255,
                255,
                255
            );


        imagefilledrectangle(
            $output,
            0,
            0,
            $newWidth,
            $newHeight,
            $white
        );


        imagecopyresampled(
            $output,
            $image,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );


        $saved =
            imagejpeg(
                $output,
                $destination,
                $quality
            );


        imagedestroy(
            $output
        );


        if (
            !$saved
        ) {

            throw new RuntimeException(
                'The processed photo could not be saved.'
            );

        }


        return [

            'width' =>
                $newWidth,

            'height' =>
                $newHeight

        ];


    } finally {

        imagedestroy(
            $image
        );

    }

}


/* =========================================================
   DESTINATION DIRECTORY
   ========================================================= */

$year =
    date(
        'Y'
    );


$month =
    date(
        'm'
    );


$userDirectory =
    'user-'
    .
    (int)
    $user[
        'id'
    ];


$relativeDirectory =
    '/uploads/scout-places/'
    .
    $year
    .
    '/'
    .
    $month
    .
    '/'
    .
    $userDirectory;


$absoluteDirectory =
    dirname(__DIR__)
    .
    $relativeDirectory;


if (
    !is_dir(
        $absoluteDirectory
    )
) {

    if (
        !mkdir(
            $absoluteDirectory,
            0755,
            true
        )
        &&
        !is_dir(
            $absoluteDirectory
        )
    ) {

        respond(
            500,
            [
                'success' =>
                    false,

                'message' =>
                    'The photo upload directory could not be created.'
            ]
        );

    }

}


/* =========================================================
   PROCESS UPLOADS
   ========================================================= */

$savedFiles =
    [];


$createdPaths =
    [];


try {

    foreach (
        $uploads as $index => $upload
    ) {

        if (
            $upload[
                'error'
            ] !==
            UPLOAD_ERR_OK
        ) {

            throw new RuntimeException(
                'One of the selected photos could not be uploaded.'
            );

        }


        if (
            $upload[
                'size'
            ] < 1
        ) {

            throw new RuntimeException(
                'One of the selected photos is empty.'
            );

        }


        if (
            $upload[
                'size'
            ] >
            $maxBytesPerPhoto
        ) {

            throw new RuntimeException(
                'Each photo must be 15 MB or smaller.'
            );

        }


        $tmp =
            $upload[
                'tmp_name'
            ];


        if (
            !is_uploaded_file(
                $tmp
            )
        ) {

            throw new RuntimeException(
                'One of the uploaded files could not be verified.'
            );

        }


        $format =
            detect_uploaded_image(
                $tmp
            );


        if (
            $format === null
        ) {

            throw new RuntimeException(
                'One of the selected files is not a supported image.'
            );

        }


        /*
         * Generate a server-controlled filename.
         *
         * No place name, GPS data, original filename,
         * username, timestamp from the camera, etc.
         */

        $filename =
            'scout-'
            .
            bin2hex(
                random_bytes(16)
            )
            .
            '.jpg';


        $absolutePath =
            $absoluteDirectory
            .
            '/'
            .
            $filename;


        $relativePath =
            $relativeDirectory
            .
            '/'
            .
            $filename;


        /*
         * Prefer Imagick because it gives us HEIC/HEIF/AVIF
         * support when the host has those codecs installed.
         */

        if (
            imagick_can_read(
                $format
            )
        ) {

            $dimensions =
                process_with_imagick(
                    $tmp,
                    $absolutePath,
                    $maxDimension,
                    $jpegQuality
                );


        } elseif (
            in_array(
                $format,
                [
                    'jpeg',
                    'png',
                    'webp'
                ],
                true
            )
        ) {

            $dimensions =
                process_with_gd(
                    $tmp,
                    $format,
                    $absolutePath,
                    $maxDimension,
                    $jpegQuality
                );


        } else {

            throw new RuntimeException(
                'Your server cannot currently convert this phone photo format. HEIC/HEIF support needs to be enabled before this format can be uploaded.'
            );

        }


        if (
            !is_file(
                $absolutePath
            )
            ||
            filesize(
                $absolutePath
            ) < 1
        ) {

            throw new RuntimeException(
                'A processed photo was not saved correctly.'
            );

        }


        $createdPaths[] =
            $absolutePath;


        $savedFiles[] = [

            'url' =>
                $relativePath,

            'filename' =>
                $filename,

            'width' =>
                (int)
                $dimensions[
                    'width'
                ],

            'height' =>
                (int)
                $dimensions[
                    'height'
                ],

            'size' =>
                (int)
                filesize(
                    $absolutePath
                ),

            'featured' =>
                $index === 0

        ];

    }


} catch (
    Throwable $exception
) {

    /*
     * If any image fails, remove everything from this batch.
     * We do not want abandoned partial uploads.
     */

    foreach (
        $createdPaths as $createdPath
    ) {

        if (
            is_file(
                $createdPath
            )
        ) {

            @unlink(
                $createdPath
            );

        }

    }


    error_log(
        'Llama Scout photo upload error: '
        .
        $exception
            ->getMessage()
    );


    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                $exception
                    ->getMessage()
        ]
    );

}


/* =========================================================
   SUCCESS
   ========================================================= */

respond(
    200,
    [
        'success' =>
            true,

        'count' =>
            count(
                $savedFiles
            ),

        'photos' =>
            $savedFiles,

        'message' =>
            count(
                $savedFiles
            ) === 1
                ? '1 photo uploaded and cleaned.'
                : count(
                    $savedFiles
                )
                  .
                  ' photos uploaded and cleaned.'
    ]
);
