<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SHARED PHOTO UPLOAD ENGINE

   Reusable image processing for Place submissions,
   Place updates, and other authenticated photo workflows.

   - up to caller-defined photo count
   - 15 MB source limit per image by default
   - JPEG / PNG / WebP / HEIC / HEIF / AVIF detection
   - automatic orientation
   - maximum 2400 px dimension by default
   - clean JPEG output
   - EXIF / GPS / metadata stripping
   - all-or-nothing batch cleanup on failure
   ========================================================= */


/* =========================================================
   NORMALIZE PHP MULTI-UPLOAD
   ========================================================= */

function llama_photo_normalize_uploads(
    array $files
): array {

    $names =
        $files['name']
        ?? null;


    if (
        !is_array(
            $names
        )
    ) {

        return [];

    }


    $uploads =
        [];


    $count =
        count(
            $names
        );


    for (
        $index = 0;
        $index < $count;
        $index++
    ) {

        $error =
            (int) (
                $files['error'][$index]
                ??
                UPLOAD_ERR_NO_FILE
            );


        if (
            $error ===
            UPLOAD_ERR_NO_FILE
        ) {

            continue;

        }


        $uploads[] = [

            'name' =>
                (string) (
                    $files['name'][$index]
                    ?? ''
                ),

            'tmp_name' =>
                (string) (
                    $files['tmp_name'][$index]
                    ?? ''
                ),

            'size' =>
                (int) (
                    $files['size'][$index]
                    ?? 0
                ),

            'error' =>
                $error,

        ];

    }


    return
        $uploads;

}


/* =========================================================
   IMAGE TYPE DETECTION
   ========================================================= */

function llama_photo_detect_image(
    string $path
): ?string {

    if (
        !is_file(
            $path
        )
    ) {

        return null;

    }


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
                'avif',

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
        )
        >=
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
                    'msf1',
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
                    'avis',
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

function llama_photo_imagick_can_read(
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
                    ),

            };


        return
            !empty(
                Imagick::queryFormats(
                    $queryFormat
                )
            );


    } catch (
        Throwable
    ) {

        return false;

    }

}


/* =========================================================
   JPEG ORIENTATION
   ========================================================= */

function llama_photo_jpeg_orientation(
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
                $exif['Orientation']
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

function llama_photo_resized_dimensions(
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
            0,
        ];

    }


    if (
        $width <= $maxDimension
        &&
        $height <= $maxDimension
    ) {

        return [
            $width,
            $height,
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
        ),

    ];

}


/* =========================================================
   IMAGICK PROCESSOR
   ========================================================= */

function llama_photo_process_imagick(
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


        if (
            $image->getNumberImages()
            > 1
        ) {

            $image->setIteratorIndex(
                0
            );

        }


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


        $image->stripImage();


        $width =
            $image->getImageWidth();


        $height =
            $image->getImageHeight();


        [
            $newWidth,
            $newHeight,
        ] =
            llama_photo_resized_dimensions(
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
                    $flattened instanceof
                    Imagick
                ) {

                    $image->clear();

                    $image =
                        $flattened;

                }


            } catch (
                Throwable
            ) {

                // Flattening is optional on builds that do not need it.

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


        return [

            'width' =>
                $image->getImageWidth(),

            'height' =>
                $image->getImageHeight(),

        ];


    } finally {

        $image->clear();

        $image->destroy();

    }

}


/* =========================================================
   GD PROCESSOR
   ========================================================= */

function llama_photo_process_gd(
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
                false,

        };


    if (
        !$image
    ) {

        throw new RuntimeException(
            'This image format cannot be decoded by the server.'
        );

    }


    try {

        if (
            $format ===
            'jpeg'
        ) {

            $orientation =
                llama_photo_jpeg_orientation(
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
            $newHeight,
        ] =
            llama_photo_resized_dimensions(
                $width,
                $height,
                $maxDimension
            );


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
                $newHeight,

        ];


    } finally {

        imagedestroy(
            $image
        );

    }

}


/* =========================================================
   SAFE STORAGE NAMESPACE
   ========================================================= */

function llama_photo_storage_namespace(
    string $namespace
): string {

    $namespace =
        strtolower(
            trim(
                $namespace
            )
        );


    if (
        !preg_match(
            '/^[a-z0-9][a-z0-9-]{0,49}$/',
            $namespace
        )
    ) {

        throw new InvalidArgumentException(
            'The photo storage namespace is invalid.'
        );

    }


    return
        $namespace;

}


/* =========================================================
   STORE PHOTO BATCH
   ========================================================= */

function llama_store_uploaded_photos(
    array $files,
    int $userId,
    string $namespace,
    int $maxPhotos = 5,
    int $maxBytesPerPhoto = 15728640,
    int $maxDimension = 2400,
    int $jpegQuality = 84
): array {

    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid uploader is required.'
        );

    }


    if (
        $maxPhotos < 1
        ||
        $maxPhotos > 20
    ) {

        throw new InvalidArgumentException(
            'The photo limit is invalid.'
        );

    }


    $namespace =
        llama_photo_storage_namespace(
            $namespace
        );


    $uploads =
        llama_photo_normalize_uploads(
            $files
        );


    if (
        !$uploads
    ) {

        throw new InvalidArgumentException(
            'Choose at least one photo.'
        );

    }


    if (
        count(
            $uploads
        )
        >
        $maxPhotos
    ) {

        throw new InvalidArgumentException(
            'You can upload up to '
            .
            $maxPhotos
            .
            ' photos at a time.'
        );

    }


    $year =
        date(
            'Y'
        );


    $month =
        date(
            'm'
        );


    $relativeDirectory =
        '/uploads/'
        .
        $namespace
        .
        '/'
        .
        $year
        .
        '/'
        .
        $month
        .
        '/user-'
        .
        $userId;


    $absoluteDirectory =
        dirname(
            __DIR__
        )
        .
        $relativeDirectory;


    if (
        !is_dir(
            $absoluteDirectory
        )
        &&
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

        throw new RuntimeException(
            'The photo upload directory could not be created.'
        );

    }


    $savedFiles =
        [];


    $createdPaths =
        [];


    try {

        foreach (
            $uploads as
            $index =>
            $upload
        ) {

            if (
                $upload['error']
                !==
                UPLOAD_ERR_OK
            ) {

                throw new RuntimeException(
                    'One of the selected photos could not be uploaded.'
                );

            }


            if (
                $upload['size']
                < 1
            ) {

                throw new RuntimeException(
                    'One of the selected photos is empty.'
                );

            }


            if (
                $upload['size']
                >
                $maxBytesPerPhoto
            ) {

                throw new RuntimeException(
                    'Each photo must be 15 MB or smaller.'
                );

            }


            $tmp =
                $upload['tmp_name'];


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
                llama_photo_detect_image(
                    $tmp
                );


            if (
                $format ===
                null
            ) {

                throw new RuntimeException(
                    'One of the selected files is not a supported image.'
                );

            }


            $filename =
                'photo-'
                .
                bin2hex(
                    random_bytes(
                        16
                    )
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


            if (
                llama_photo_imagick_can_read(
                    $format
                )
            ) {

                $dimensions =
                    llama_photo_process_imagick(
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
                        'webp',
                    ],
                    true
                )
            ) {

                $dimensions =
                    llama_photo_process_gd(
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
                    $dimensions['width'],

                'height' =>
                    (int)
                    $dimensions['height'],

                'size' =>
                    (int)
                    filesize(
                        $absolutePath
                    ),

                'featured' =>
                    $index === 0,

            ];

        }


    } catch (
        Throwable $exception
    ) {

        foreach (
            $createdPaths as
            $createdPath
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


        throw
            $exception;

    }


    return
        $savedFiles;

}


/* =========================================================
   DELETE MANAGED PHOTO
   ========================================================= */

function llama_delete_managed_photo(
    string $relativePath,
    int $userId,
    string $namespace
): bool {

    if (
        $userId < 1
    ) {

        return false;

    }


    $namespace =
        llama_photo_storage_namespace(
            $namespace
        );


    $relativePath =
        trim(
            $relativePath
        );


    $requiredPrefix =
        '/uploads/'
        .
        $namespace
        .
        '/';


    if (
        !str_starts_with(
            $relativePath,
            $requiredPrefix
        )
        ||
        !str_contains(
            $relativePath,
            '/user-'
            .
            $userId
            .
            '/'
        )
    ) {

        return false;

    }


    $absolutePath =
        dirname(
            __DIR__
        )
        .
        $relativePath;


    if (
        !is_file(
            $absolutePath
        )
    ) {

        return true;

    }


    return
        @unlink(
            $absolutePath
        );

}
