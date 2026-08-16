<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_verified_email();

$user = current_user();

start_llama_session();

$db = db();


/* =========================================================
   HELPERS
   ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function remove_uploaded_files(array $paths): void
{
    foreach ($paths as $path) {

        if (
            is_string($path) &&
            is_file($path)
        ) {
            @unlink($path);
        }
    }
}


/*
 * Detect an uploaded image based primarily on
 * its real contents rather than trusting the
 * browser-provided extension or MIME type.
 *
 * Returns:
 *
 * [
 *   'extension' => 'jpg',
 *   'mime_type' => 'image/jpeg'
 * ]
 *
 * or null when unsupported/invalid.
 */

function detect_image_upload_format(
    string $tmpName,
    string $originalName
): ?array {

    if (
        !is_file($tmpName) ||
        filesize($tmpName) < 8
    ) {
        return null;
    }


    $handle =
        @fopen(
            $tmpName,
            'rb'
        );


    if (!$handle) {
        return null;
    }


    $header =
        fread(
            $handle,
            64
        );


    fclose($handle);


    if (
        !is_string($header) ||
        strlen($header) < 8
    ) {
        return null;
    }


    /* =====================================================
       JPEG / JPEG_R / ULTRA HDR JPEG

       Standard JPEG magic:
       FF D8 FF
       ===================================================== */

    if (
        substr(
            $header,
            0,
            3
        ) === "\xFF\xD8\xFF"
    ) {

        return [
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg'
        ];
    }


    /* =====================================================
       PNG

       89 50 4E 47 0D 0A 1A 0A
       ===================================================== */

    if (
        substr(
            $header,
            0,
            8
        ) ===
        "\x89PNG\x0D\x0A\x1A\x0A"
    ) {

        return [
            'extension' => 'png',
            'mime_type' => 'image/png'
        ];
    }


    /* =====================================================
       WEBP

       RIFF .... WEBP
       ===================================================== */

    if (
        substr(
            $header,
            0,
            4
        ) === 'RIFF' &&
        substr(
            $header,
            8,
            4
        ) === 'WEBP'
    ) {

        return [
            'extension' => 'webp',
            'mime_type' => 'image/webp'
        ];
    }


    /* =====================================================
       HEIC / HEIF / AVIF

       These are ISO Base Media File Format containers.

       Common structure:
       ....ftypXXXX

       where XXXX identifies the brand.
       ===================================================== */

    $ftypPosition =
        strpos(
            $header,
            'ftyp'
        );


    if (
        $ftypPosition !== false &&
        strlen($header) >=
            $ftypPosition + 8
    ) {

        $brand =
            strtolower(
                substr(
                    $header,
                    $ftypPosition + 4,
                    4
                )
            );


        /*
         * HEIC / HEIF brands.
         */

        $heifBrands = [

            'heic',
            'heix',
            'hevc',
            'hevx',
            'heim',
            'heis',

            /*
             * Generic HEIF containers.
             */

            'mif1',
            'msf1'
        ];


        if (
            in_array(
                $brand,
                $heifBrands,
                true
            )
        ) {

            $originalExtension =
                strtolower(
                    pathinfo(
                        $originalName,
                        PATHINFO_EXTENSION
                    )
                );


            /*
             * Preserve HEIF extension when
             * the actual upload used .heif.
             */

            $extension =
                $originalExtension === 'heif'
                    ? 'heif'
                    : 'heic';


            return [
                'extension' => $extension,
                'mime_type' =>
                    $extension === 'heif'
                        ? 'image/heif'
                        : 'image/heic'
            ];
        }


        /*
         * AVIF brands.
         */

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

            return [
                'extension' => 'avif',
                'mime_type' => 'image/avif'
            ];
        }
    }


    /* =====================================================
       MIME FALLBACK

       Useful when a valid system MIME detector
       recognizes a supported format that was
       not caught above.

       We still restrict this to known image types.
       ===================================================== */

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );


    $mime =
        $finfo->file(
            $tmpName
        );


    if (!is_string($mime)) {
        return null;
    }


    return match ($mime) {

        'image/jpeg',
        'image/jpeg_r' =>
            [
                'extension' => 'jpg',
                'mime_type' => $mime
            ],

        'image/png' =>
            [
                'extension' => 'png',
                'mime_type' => 'image/png'
            ],

        'image/webp' =>
            [
                'extension' => 'webp',
                'mime_type' => 'image/webp'
            ],

        'image/heic',
        'image/vnd.android.heic',
        'image/heic-sequence' =>
            [
                'extension' => 'heic',
                'mime_type' => 'image/heic'
            ],

        'image/heif',
        'image/heif-sequence' =>
            [
                'extension' => 'heif',
                'mime_type' => 'image/heif'
            ],

        'image/avif' =>
            [
                'extension' => 'avif',
                'mime_type' => 'image/avif'
            ],

        default =>
            null
    };
}


/* =========================================================
   PLACE
   ========================================================= */

$slug =
    trim(
        (string) (
            $_GET['place']
            ?? $_POST['place_slug']
            ?? ''
        )
    );


if ($slug === '') {

    http_response_code(400);

    exit(
        'A place is required.'
    );
}


$placeStmt =
    $db->prepare(
        '
        SELECT
            id,
            slug,
            name,
            status,
            city,
            state

        FROM places

        WHERE slug = ?
          AND status IN (
              "active",
              "featured"
          )

        LIMIT 1
        '
    );


$placeStmt->execute([
    $slug
]);


$place =
    $placeStmt->fetch(
        PDO::FETCH_ASSOC
    );


if (!$place) {

    http_response_code(404);

    exit(
        'That place is not currently available.'
    );
}


/* =========================================================
   CSRF TOKEN
   ========================================================= */

if (
    empty(
        $_SESSION[
            'report_place_csrf'
        ]
    )
) {

    $_SESSION[
        'report_place_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );
}


$csrfToken =
    $_SESSION[
        'report_place_csrf'
    ];


/* =========================================================
   REPORT TYPES
   ========================================================= */

$allowedTypes = [

    'closed' =>
        'Place is closed or inaccessible',

    'camping-not-allowed' =>
        'Camping is no longer allowed',

    'road-access' =>
        'Road or access conditions changed',

    'incorrect-information' =>
        'Information is incorrect',

    'safety' =>
        'Safety concern',

    'seasonal-closure' =>
        'Seasonal or temporary closure',

    'location' =>
        'Location or coordinates are wrong',

    'private-property' =>
        'Private property concern',

    'duplicate' =>
        'Duplicate place',

    'other' =>
        'Other'
];


/* =========================================================
   FORM STATE
   ========================================================= */

$problemType = '';

$details = '';

$error = '';

$success = false;

$submittedReportId = null;

$successfulPhotoCount = 0;


/* =========================================================
   UPLOAD SETTINGS
   ========================================================= */

$maxPhotos = 3;

$maxPhotoBytes =
    8 * 1024 * 1024;


/* =========================================================
   SUBMIT REPORT
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        !is_string(
            $submittedToken
        ) ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $problemType =
            trim(
                (string) (
                    $_POST[
                        'problem_type'
                    ]
                    ?? ''
                )
            );


        $details =
            trim(
                (string) (
                    $_POST['details']
                    ?? ''
                )
            );


        /* =================================================
           VALIDATE REPORT
           ================================================= */

        if (
            !array_key_exists(
                $problemType,
                $allowedTypes
            )
        ) {

            $error =
                'Choose the type of problem you found.';

        } elseif (
            mb_strlen(
                $details
            ) < 10
        ) {

            $error =
                'Please give us a little more detail about what changed or what you found.';

        } elseif (
            mb_strlen(
                $details
            ) > 3000
        ) {

            $error =
                'Please keep your report under 3,000 characters.';
        }


        /* =================================================
           BUILD UPLOAD LIST
           ================================================= */

        $uploads = [];


        if (
            $error === '' &&
            isset(
                $_FILES['photos']
            ) &&
            is_array(
                $_FILES['photos']['name']
                ?? null
            )
        ) {

            $photoCount =
                count(
                    $_FILES[
                        'photos'
                    ]['name']
                );


            for (
                $i = 0;
                $i < $photoCount;
                $i++
            ) {

                $uploadError =
                    $_FILES[
                        'photos'
                    ]['error'][$i]
                    ?? UPLOAD_ERR_NO_FILE;


                if (
                    $uploadError
                    === UPLOAD_ERR_NO_FILE
                ) {
                    continue;
                }


                $uploads[] = [

                    'name' =>
                        $_FILES[
                            'photos'
                        ]['name'][$i]
                        ?? '',

                    'tmp_name' =>
                        $_FILES[
                            'photos'
                        ]['tmp_name'][$i]
                        ?? '',

                    'size' =>
                        $_FILES[
                            'photos'
                        ]['size'][$i]
                        ?? 0,

                    'error' =>
                        $uploadError
                ];
            }
        }


        /* =================================================
           NUMBER OF PHOTOS
           ================================================= */

        if (
            $error === '' &&
            count($uploads) >
            $maxPhotos
        ) {

            $error =
                'You can upload up to 3 photos with a report.';
        }


        /* =================================================
           VALIDATE PHOTOS
           ================================================= */

        $validatedUploads = [];


        if (
            $error === '' &&
            $uploads
        ) {

            foreach (
                $uploads as
                $index => $upload
            ) {

                if (
                    $upload['error']
                    !== UPLOAD_ERR_OK
                ) {

                    $error =
                        'One of your photos could not be uploaded. Please try again.';

                    break;
                }


                if (
                    (int)
                    $upload['size'] >
                    $maxPhotoBytes
                ) {

                    $error =
                        'Each photo must be 8 MB or smaller.';

                    break;
                }


                if (
                    (int)
                    $upload['size']
                    < 1
                ) {

                    $error =
                        'One of the uploaded photos is empty.';

                    break;
                }


                $tmpName =
                    (string)
                    $upload[
                        'tmp_name'
                    ];


                if (
                    !is_uploaded_file(
                        $tmpName
                    )
                ) {

                    $error =
                        'One of the uploaded files could not be verified.';

                    break;
                }


                /* =========================================
                   DETECT ACTUAL IMAGE FORMAT
                   ========================================= */

                $detected =
                    detect_image_upload_format(
                        $tmpName,
                        (string)
                        $upload['name']
                    );


                if ($detected === null) {

                    $error =
                        'That photo format is not supported. Please upload a photo from your phone or camera.';

                    break;
                }


                /* =========================================
                   ADDITIONAL VALIDATION FOR FORMATS
                   PHP CAN DECODE RELIABLY

                   HEIC / HEIF / AVIF are already
                   container-validated above and may not
                   be decodable by every web server.
                   ========================================= */

                if (
                    in_array(
                        $detected[
                            'extension'
                        ],
                        [
                            'jpg',
                            'png',
                            'webp'
                        ],
                        true
                    )
                ) {

                    $imageInfo =
                        @getimagesize(
                            $tmpName
                        );


                    if (
                        $imageInfo === false
                    ) {

                        $error =
                            'One of the uploaded files is not a valid image.';

                        break;
                    }
                }


                $validatedUploads[] = [

                    'tmp_name' =>
                        $tmpName,

                    'original_name' =>
                        basename(
                            (string)
                            $upload['name']
                        ),

                    'size' =>
                        (int)
                        $upload['size'],

                    'mime_type' =>
                        $detected[
                            'mime_type'
                        ],

                    'extension' =>
                        $detected[
                            'extension'
                        ],

                    'sort_order' =>
                        $index
                ];
            }
        }


        /* =================================================
           EXISTING OPEN REPORT
           ================================================= */

        if ($error === '') {

            $existingStmt =
                $db->prepare(
                    '
                    SELECT id

                    FROM place_reports

                    WHERE place_id = ?
                      AND user_id = ?
                      AND status IN (
                          "open",
                          "investigating"
                      )

                    LIMIT 1
                    '
                );


            $existingStmt->execute([
                $place['id'],
                $user['id']
            ]);


            if (
                $existingStmt->fetch()
            ) {

                $error =
                    'You already have an open report for this place.';
            }
        }


        /* =================================================
           SAVE REPORT + PHOTOS
           ================================================= */

        if ($error === '') {

            $movedFiles = [];


            try {

                $db->beginTransaction();


                /* =========================================
                   CREATE REPORT
                   ========================================= */

                $insertReport =
                    $db->prepare(
                        '
                        INSERT INTO place_reports (
                            place_id,
                            user_id,
                            problem_type,
                            details,
                            status
                        )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            ?,
                            "open"
                        )
                        '
                    );


                $insertReport->execute([

                    $place['id'],

                    $user['id'],

                    $problemType,

                    $details
                ]);


                $reportId =
                    (int)
                    $db->lastInsertId();


                /* =========================================
                   PHOTO DIRECTORY
                   ========================================= */

                if ($validatedUploads) {

                    $year =
                        date('Y');

                    $month =
                        date('m');


                    $relativeDirectory =
                        '/uploads/place-reports/' .
                        $year .
                        '/' .
                        $month;


                    $absoluteDirectory =
                        dirname(__DIR__) .
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
                            ) &&
                            !is_dir(
                                $absoluteDirectory
                            )
                        ) {

                            throw new RuntimeException(
                                'The report photo directory could not be created.'
                            );
                        }
                    }


                    /* =====================================
                       MOVE PHOTOS
                       ===================================== */

                    foreach (
                        $validatedUploads as
                        $upload
                    ) {

                        $randomName =
                            'report-' .
                            $reportId .
                            '-' .
                            bin2hex(
                                random_bytes(12)
                            ) .
                            '.' .
                            $upload[
                                'extension'
                            ];


                        $absolutePath =
                            $absoluteDirectory .
                            '/' .
                            $randomName;


                        $relativePath =
                            $relativeDirectory .
                            '/' .
                            $randomName;


                        if (
                            !move_uploaded_file(
                                $upload[
                                    'tmp_name'
                                ],
                                $absolutePath
                            )
                        ) {

                            throw new RuntimeException(
                                'A report photo could not be saved.'
                            );
                        }


                        $movedFiles[] =
                            $absolutePath;


                        /* =================================
                           DATABASE PHOTO RECORD
                           ================================= */

                        $imageStmt =
                            $db->prepare(
                                '
                                INSERT INTO
                                    place_report_images
                                (
                                    report_id,
                                    file_path,
                                    original_name,
                                    mime_type,
                                    file_size,
                                    sort_order
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?
                                )
                                '
                            );


                        $imageStmt->execute([

                            $reportId,

                            $relativePath,

                            $upload[
                                'original_name'
                            ],

                            $upload[
                                'mime_type'
                            ],

                            $upload[
                                'size'
                            ],

                            $upload[
                                'sort_order'
                            ]
                        ]);
                    }
                }


                $db->commit();


                $submittedReportId =
                    $reportId;

                $successfulPhotoCount =
                    count(
                        $validatedUploads
                    );

                $success =
                    true;

                $problemType =
                    '';

                $details =
                    '';


                /*
                 * Rotate token after success.
                 */

                $_SESSION[
                    'report_place_csrf'
                ] =
                    bin2hex(
                        random_bytes(32)
                    );


                $csrfToken =
                    $_SESSION[
                        'report_place_csrf'
                    ];


            } catch (
                Throwable $exception
            ) {

                if (
                    $db->inTransaction()
                ) {

                    $db->rollBack();
                }


                /*
                 * Database rollback cannot
                 * delete files already moved.
                 */

                remove_uploaded_files(
                    $movedFiles
                );


                error_log(
                    'Llama Scout place report error: ' .
                    $exception->getMessage()
                );


                $error =
                    'Something went wrong while saving your report.';
            }
        }
    }
}

?>
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
  name="viewport"
  content="width=device-width, initial-scale=1"
>

<title>
  Mark a Problem | Llama Scout
</title>

<meta
  name="robots"
  content="noindex,nofollow"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<style>

body {
  margin: 0;

  background: #f4efe6;
  color: #172822;

  font-family:
    system-ui,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;
}

.page {
  width: min(
    760px,
    calc(100% - 36px)
  );

  margin: 0 auto;

  padding: 45px 0 80px;
}

.back {
  display: inline-block;

  margin-bottom: 24px;

  color: inherit;

  font-weight: 700;

  text-decoration: none;
}

.card {
  padding: 28px;

  background: #fff;

  border:
    1px solid
    rgba(0,0,0,.09);

  border-radius: 14px;
}

.eyebrow {
  margin: 0 0 8px;

  color: #707870;

  font-size: .75rem;
  font-weight: 800;

  text-transform: uppercase;
  letter-spacing: .06em;
}

h1 {
  margin: 0 0 10px;
}

.place-name {
  margin: 0 0 24px;

  color: #667069;

  line-height: 1.5;
}

.intro {
  margin-bottom: 28px;

  line-height: 1.65;
}

.notice {
  padding: 15px 17px;

  margin-bottom: 22px;

  border-radius: 8px;

  line-height: 1.55;
}

.notice-error {
  background: #f8e3df;

  border-left:
    5px solid #9b443d;
}

.notice-success {
  background: #e4f1e7;

  border-left:
    5px solid #436d50;
}

.field + .field {
  margin-top: 20px;
}

label {
  display: block;

  margin-bottom: 7px;

  font-weight: 800;
}

select,
textarea,
input[type="file"] {
  width: 100%;

  box-sizing: border-box;

  font: inherit;
}

select,
textarea {
  padding: 12px 13px;

  border:
    1px solid
    rgba(0,0,0,.2);

  border-radius: 8px;

  background: #fff;
}

textarea {
  min-height: 160px;

  resize: vertical;
}

input[type="file"] {
  padding: 12px;

  border:
    1px dashed
    rgba(0,0,0,.25);

  border-radius: 8px;

  background: #f9f7f2;
}

.help {
  margin: 7px 0 0;

  color: #737b76;

  font-size: .82rem;

  line-height: 1.5;
}

.photo-note {
  margin: 10px 0 0;

  padding: 12px 14px;

  background: #f4f0e5;

  border-radius: 7px;

  font-size: .84rem;

  line-height: 1.55;
}

button {
  margin-top: 24px;

  padding: 12px 17px;

  border: 0;

  border-radius: 8px;

  background: #172822;
  color: #fff;

  font: inherit;
  font-weight: 800;

  cursor: pointer;
}

.success-actions {
  display: flex;

  flex-wrap: wrap;

  gap: 10px;

  margin-top: 20px;
}

.success-actions a {
  display: inline-block;

  padding: 10px 14px;

  border-radius: 7px;

  text-decoration: none;

  font-weight: 800;
}

.primary-link {
  background: #172822;

  color: #fff;
}

.secondary-link {
  color: #172822;

  border:
    1px solid
    rgba(0,0,0,.15);
}

.report-number {
  margin-top: 12px;

  color: #68716c;

  font-size: .84rem;
}

@media (
  max-width: 600px
) {

  .card {
    padding: 22px 18px;
  }

}

</style>

</head>

<body>


<main class="page">


<a
  class="back"
  href="https://llamascout.com/place.html?place=<?= urlencode(
      $place['slug']
  ) ?>"
>
  ← Back to place
</a>


<section class="card">


<p class="eyebrow">
  Community Report
</p>


<h1>
  Mark a Problem
</h1>


<p class="place-name">

  <?= e(
      $place['name']
  ) ?>


  <?php if (
      $place['city'] ||
      $place['state']
  ): ?>

    ·

    <?= e(
        implode(
            ', ',
            array_filter([
                $place['city'],
                $place['state']
            ])
        )
    ) ?>

  <?php endif; ?>

</p>


<?php if ($success): ?>


  <div
    class="
      notice
      notice-success
    "
  >

    <strong>
      Report received.
    </strong>

    <br><br>

    Thanks for flagging this place.

    Your report is now waiting
    for review.


    <?php if (
        $successfulPhotoCount > 0
    ): ?>

      <br><br>

      <?= $successfulPhotoCount ?>

      <?= $successfulPhotoCount === 1
          ? 'photo was'
          : 'photos were'
      ?>

      attached successfully.

    <?php endif; ?>


    <br><br>

    The place has not been
    automatically removed or
    unlisted.

  </div>


  <?php if (
      $submittedReportId
  ): ?>

    <div class="report-number">

      Report #<?= (int)
          $submittedReportId
      ?>

    </div>

  <?php endif; ?>


  <div class="success-actions">

    <a
      class="primary-link"
      href="https://llamascout.com/place.html?place=<?= urlencode(
          $place['slug']
      ) ?>"
    >
      Return to Place
    </a>


    <a
      class="secondary-link"
      href="/"
    >
      Account
    </a>

  </div>


<?php else: ?>


  <p class="intro">

    If something about this place
    has changed, tell us what you
    found.

    Reports are reviewed before a
    place is unlisted or removed.

  </p>


  <?php if ($error): ?>

    <div
      class="
        notice
        notice-error
      "
    >
      <?= e($error) ?>
    </div>

  <?php endif; ?>


  <form
    method="post"
    enctype="multipart/form-data"
  >


    <input
      type="hidden"
      name="place_slug"
      value="<?= e(
          $place['slug']
      ) ?>"
    >


    <input
      type="hidden"
      name="csrf_token"
      value="<?= e(
          $csrfToken
      ) ?>"
    >


    <div class="field">

      <label for="problem_type">
        What’s wrong?
      </label>


      <select
        id="problem_type"
        name="problem_type"
        required
      >

        <option value="">
          Choose a problem
        </option>


        <?php foreach (
            $allowedTypes as
            $value => $label
        ): ?>

          <option
            value="<?= e(
                $value
            ) ?>"
            <?= (
                $problemType
                === $value
            )
                ? 'selected'
                : ''
            ?>
          >

            <?= e($label) ?>

          </option>

        <?php endforeach; ?>

      </select>

    </div>


    <div class="field">

      <label for="details">
        What did you find?
      </label>


      <textarea
        id="details"
        name="details"
        maxlength="3000"
        required
        placeholder="Example: There is now a No Camping sign at the entrance, dated August 2026."
      ><?= e(
          $details
      ) ?></textarea>


      <p class="help">

        Include anything that could
        help verify the change, such
        as signs, dates, road
        conditions, or what you saw
        while you were there.

      </p>

    </div>


    <div class="field">

      <label for="photos">
        Photos
      </label>


      <input
        id="photos"
        name="photos[]"
        type="file"
        accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif,.avif"
        multiple
      >


      <p class="help">

        Optional. Upload up to
        3 photos.

        Photos directly from iPhone,
        Android, Samsung Galaxy, and
        most digital cameras are
        supported.

        Maximum 8 MB per photo.

      </p>


      <div class="photo-note">

        Clear photos can help us
        verify a report faster.

        Signs, gates, closures,
        road damage, access changes,
        and other visible conditions
        are especially useful.

      </div>

    </div>


    <button type="submit">
      Submit Report
    </button>


  </form>


<?php endif; ?>


</section>


</main>


</body>

</html>
