<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/place-updates.php';

require_once
    dirname(__DIR__)
    . '/app/place-update-approval.php';

require_once
    dirname(__DIR__)
    . '/app/place-update-conflicts.php';


require_verified_email();


start_llama_session();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user[
        'id'
    ];


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


function update_form_label(
    string $path
): string {

    $last =
        explode(
            '.',
            $path
        );


    $label =
        (string)
        end(
            $last
        );


    $label =
        preg_replace(
            '/([a-z])([A-Z])/',
            '$1 $2',
            $label
        )
        ?? $label;


    return ucwords(
        str_replace(
            [
                '_',
                '-',
            ],
            ' ',
            $label
        )
    );

}


function update_form_group_label(
    string $path
): string {

    $parts =
        explode(
            '.',
            $path
        );


    $group =
        (string)
        (
            $parts[0]
            ?? 'place'
        );


    return match ($group) {

        'site' =>
            'Site Details',

        'access' =>
            'Access',

        'sensory' =>
            'Sensory',

        'connectivity' =>
            'Connectivity',

        'amenities' =>
            'Amenities',

        'environment' =>
            'Environment',

        'experience' =>
            'Experience',

        'accessibility' =>
            'Accessibility',

        'safety' =>
            'Safety',

        'location' =>
            'Location',

        default =>
            'Place Details',

    };

}


function update_form_display_value(
    mixed $value,
    string $type
): string {

    if (
        $value === null
    ) {

        return 'Unknown';

    }


    if (
        $type ===
        'bool'
    ) {

        return
            (bool)
            $value
                ? 'Yes'
                : 'No';

    }


    if (
        $value === ''
    ) {

        return 'Blank';

    }


    return
        (string)
        $value;

}


function update_form_token(
    string $path
): string {

    return
        substr(
            hash(
                'sha256',
                $path
            ),
            0,
            20
        );

}


function update_form_parse_value(
    mixed $rawValue,
    string $type
): mixed {

    if (
        is_array(
            $rawValue
        )
    ) {

        throw new InvalidArgumentException(
            'One of the submitted field values was invalid.'
        );

    }


    $raw =
        trim(
            (string)
            $rawValue
        );


    if (
        $raw ===
        '__NULL__'
    ) {

        return null;

    }


    return match ($type) {

        'bool' =>
            match ($raw) {

                '1' =>
                    true,

                '0' =>
                    false,

                default =>
                    throw new InvalidArgumentException(
                        'Choose Yes, No, or Unknown for each selected yes/no field.'
                    ),

            },

        'int' =>
            (
                $raw === ''
                    ? null
                    : (
                        filter_var(
                            $raw,
                            FILTER_VALIDATE_INT
                        )
                        !== false
                            ? (int)
                                $raw
                            : throw new InvalidArgumentException(
                                'One of the selected number fields must contain a whole number.'
                            )
                    )
            ),

        'float' =>
            (
                $raw === ''
                    ? null
                    : (
                        is_numeric(
                            $raw
                        )
                            ? (float)
                                $raw
                            : throw new InvalidArgumentException(
                                'One of the selected number fields must contain a valid number.'
                            )
                    )
            ),

        default =>
            (
                $raw === ''
                    ? null
                    : $raw
            ),

    };

}


/* =========================================================
   EDIT EXISTING RETURNED UPDATE
   ========================================================= */

$editUpdateId =
    (int) (
        $_GET[
            'edit'
        ]
        ??
        $_POST[
            'edit_update_id'
        ]
        ??
        0
    );


$editUpdate =
    null;


if (
    $editUpdateId > 0
) {

    $editUpdate =
        llama_place_update(
            $db,
            $editUpdateId
        );


    if (
        !$editUpdate
        ||
        (int)
        $editUpdate[
            'user_id'
        ]
        !==
        $userId
    ) {

        http_response_code(
            404
        );


        exit(
            'That Place update could not be found.'
        );

    }


    if (
        (string)
        $editUpdate[
            'status'
        ]
        !==
        LLAMA_UPDATE_NEEDS_CHANGES
    ) {

        http_response_code(
            409
        );


        exit(
            'Only a Place update returned for changes can be revised.'
        );

    }

}


/* =========================================================
   PLACE
   ========================================================= */

$slug =
    trim(
        (string) (
            $_GET[
                'place'
            ]
            ??
            $_POST[
                'place_slug'
            ]
            ??
            ''
        )
    );


if (
    $editUpdate
) {

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

            WHERE id = ?

              AND status IN
              (
                  \'active\',
                  \'featured\'
              )

            LIMIT 1
            '
        );


    $placeStmt->execute([
        (int)
        $editUpdate[
            'place_id'
        ]
    ]);


} else {

    if (
        $slug === ''
    ) {

        http_response_code(
            400
        );


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

              AND status IN
              (
                  \'active\',
                  \'featured\'
              )

            LIMIT 1
            '
        );


    $placeStmt->execute([
        $slug
    ]);

}


$place =
    $placeStmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$place
) {

    http_response_code(
        404
    );


    exit(
        'That place is not currently available.'
    );

}


$placeId =
    (int)
    $place[
        'id'
    ];


$slug =
    (string)
    $place[
        'slug'
    ];


/* =========================================================
   STORAGE
   ========================================================= */

llama_ensure_place_updates_table(
    $db
);


/* =========================================================
   FIELD DEFINITIONS
   ========================================================= */

$fieldMap =
    llama_place_update_field_map();


$fieldTokens =
    [];


$tokenPaths =
    [];


$groupedFields =
    [];


foreach (
    $fieldMap as
    $path =>
    $definition
) {

    $path =
        (string)
        $path;


    $token =
        update_form_token(
            $path
        );


    $fieldTokens[
        $path
    ] =
        $token;


    $tokenPaths[
        $token
    ] =
        $path;


    $group =
        update_form_group_label(
            $path
        );


    $groupedFields[
        $group
    ][
        $path
    ] =
        $definition;

}


/* =========================================================
   CURRENT VALUES FOR DISPLAY
   ========================================================= */

$currentValues =
    llama_place_update_current_values(
        $db,
        $placeId,
        $fieldMap
    );


/* =========================================================
   OPEN UPDATE
   ========================================================= */

$hasOpenUpdate =
    llama_user_has_open_place_update(
        $db,
        $placeId,
        $userId
    );


/*
 * The returned update being edited is itself the user's open
 * update, so it must not block its own revision form.
 */

if (
    $editUpdate
) {

    $hasOpenUpdate =
        false;

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'update_place_csrf'
        ]
    )
) {

    $_SESSION[
        'update_place_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    (string)
    $_SESSION[
        'update_place_csrf'
    ];


/* =========================================================
   FORM STATE
   ========================================================= */

$error =
    '';


$success =
    false;


$submittedUpdateId =
    null;


$updateType =
    LLAMA_PLACE_UPDATE;


$visitedDate =
    '';


$contributorNotes =
    '';


$selectedTokens =
    [];


$postedValues =
    [];


/* =========================================================
   PRELOAD RETURNED UPDATE
   ========================================================= */

if (
    $editUpdate
    &&
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
) {

    $updateType =
        (string)
        $editUpdate[
            'update_type'
        ];


    $visitedDate =
        !empty(
            $editUpdate[
                'visited_at'
            ]
        )
            ? substr(
                (string)
                $editUpdate[
                    'visited_at'
                ],
                0,
                10
            )
            : '';


    $contributorNotes =
        trim(
            (string) (
                $editUpdate[
                    'contributor_notes'
                ]
                ?? ''
            )
        );


    $existingChanges =
        is_array(
            $editUpdate[
                'proposed_changes'
            ]
            ?? null
        )
            ? $editUpdate[
                'proposed_changes'
            ]
            : [];


    foreach (
        llama_update_field_paths(
            $existingChanges
        )
        as
        $existingPath
    ) {

        if (
            !isset(
                $fieldTokens[
                    $existingPath
                ]
            )
        ) {

            continue;

        }


        $token =
            $fieldTokens[
                $existingPath
            ];


        $definition =
            $fieldMap[
                $existingPath
            ];


        $type =
            (string)
            (
                $definition[2]
                ?? 'string'
            );


        $value =
            llama_update_get(
                $existingChanges,
                $existingPath
            );


        $selectedTokens[] =
            $token;


        if (
            $value === null
        ) {

            $postedValues[
                $token
            ] =
                $type ===
                'bool'
                    ? '__NULL__'
                    : '';

        } elseif (
            $type ===
            'bool'
        ) {

            $postedValues[
                $token
            ] =
                $value
                    ? '1'
                    : '0';

        } else {

            $postedValues[
                $token
            ] =
                (string)
                $value;

        }

    }

}


/* =========================================================
   SUBMIT
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] ===
    'POST'
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } elseif (
        $hasOpenUpdate
    ) {

        $error =
            'You already have an update for this Place awaiting review or changes.';

    } else {

        $updateType =
            $editUpdate

                ? (string) (
                    $editUpdate[
                        'update_type'
                    ]
                    ?? LLAMA_PLACE_UPDATE
                )

                : LLAMA_PLACE_UPDATE;


        $visitedDate =
            trim(
                (string) (
                    $_POST[
                        'visited_date'
                    ]
                    ?? ''
                )
            );


        $contributorNotes =
            trim(
                (string) (
                    $_POST[
                        'contributor_notes'
                    ]
                    ?? ''
                )
            );


        $selectedTokens =
            is_array(
                $_POST[
                    'change'
                ]
                ?? null
            )
                ? array_values(
                    $_POST[
                        'change'
                    ]
                )
                : [];


        $postedValues =
            is_array(
                $_POST[
                    'value'
                ]
                ?? null
            )
                ? $_POST[
                    'value'
                ]
                : [];


        try {

            if (
                !llama_valid_place_update_type(
                    $updateType
                )
            ) {

                throw new InvalidArgumentException(
                    'Choose whether this is a Place update or a factual correction.'
                );

            }


            if (
                mb_strlen(
                    $contributorNotes
                )
                > 3000
            ) {

                throw new InvalidArgumentException(
                    'Please keep your notes under 3,000 characters.'
                );

            }


            if (
                !$selectedTokens
            ) {

                throw new InvalidArgumentException(
                    'Select at least one Place field to change.'
                );

            }


            $selectedTokens =
                array_values(
                    array_unique(
                        array_map(
                            static fn (
                                mixed $value
                            ): string =>
                                trim(
                                    (string)
                                    $value
                                ),
                            $selectedTokens
                        )
                    )
                );


            $proposedChanges =
                [];


            $originalValues =
                [];


            $actualChangeCount =
                0;


            foreach (
                $selectedTokens as
                $fieldToken
            ) {

                if (
                    !isset(
                        $tokenPaths[
                            $fieldToken
                        ]
                    )
                ) {

                    throw new InvalidArgumentException(
                        'One of the selected Place fields was invalid.'
                    );

                }


                $path =
                    $tokenPaths[
                        $fieldToken
                    ];


                $definition =
                    $fieldMap[
                        $path
                    ];


                $type =
                    (string)
                    (
                        $definition[2]
                        ?? 'string'
                    );


                $rawValue =
                    $postedValues[
                        $fieldToken
                    ]
                    ?? '';


                $proposedValue =
                    update_form_parse_value(
                        $rawValue,
                        $type
                    );


                /*
                 * Original snapshots are always read from the
                 * canonical database here. They never come
                 * from a hidden form field.
                 */

                $originalValue =
                    llama_update_current_field_value(
                        $db,
                        $placeId,
                        $path,
                        $fieldMap
                    );


                if (
                    llama_update_conflict_values_equal(
                        $originalValue,
                        $proposedValue,
                        $type
                    )
                ) {

                    continue;

                }


                llama_update_set(
                    $proposedChanges,
                    $path,
                    $proposedValue
                );


                llama_update_set(
                    $originalValues,
                    $path,
                    $originalValue
                );


                $actualChangeCount++;

            }


            if (
                $actualChangeCount < 1
            ) {

                throw new InvalidArgumentException(
                    'None of the selected values are different from the current Place information.'
                );

            }


                  $photoJson =
                $_POST[
                    'update_photos_json'
                ]
                ?? '';


            if (
                is_array(
                    $photoJson
                )
            ) {

                throw new InvalidArgumentException(
                    'The submitted photo information was invalid.'
                );

            }


            $photoJson =
                trim(
                    (string)
                    $photoJson
                );


            if (
                $photoJson === ''
            ) {

                $updatePhotos =
                    $editUpdate
                    &&
                    is_array(
                        $editUpdate[
                            'photos'
                        ]
                        ?? null
                    )
                        ? $editUpdate[
                            'photos'
                        ]
                        : [];


            } else {

                $decodedPhotos =
                    json_decode(
                        $photoJson,
                        true
                    );


                if (
                    !is_array(
                        $decodedPhotos
                    )
                ) {

                    throw new InvalidArgumentException(
                        'The submitted photo information could not be read.'
                    );

                }


                $updatePhotos =
                    $decodedPhotos;

            }


            if (
                $editUpdate
            ) {

                llama_resubmit_place_update(
                    $db,
                    $editUpdateId,
                    $userId,
                    $proposedChanges,
                    $originalValues,
                    $updateType,
                    $visitedDate !== ''
                        ? $visitedDate
                        : null,
                    $contributorNotes !== ''
                        ? $contributorNotes
                        : null,
                    $updatePhotos
                );


                $submittedUpdateId =
                    $editUpdateId;


            } else {

                $submittedUpdateId =
                    llama_create_place_update(
                        $db,
                        $placeId,
                        $userId,
                        $proposedChanges,
                        $updateType,
                        $visitedDate !== ''
                            ? $visitedDate
                            : null,
                        $contributorNotes !== ''
                            ? $contributorNotes
                            : null,
                        $originalValues,
                        $updatePhotos
                    );

            }

            $success =
                true;


            $hasOpenUpdate =
                true;


        } catch (
            Throwable $exception
        ) {

            $error =
                $exception
                    ->getMessage();

        }

    }

}


/* =========================================================
   DISPLAY
   ========================================================= */

$placeLocation =
    trim(
        implode(
            ', ',
            array_filter(
                [
                    trim(
                        (string) (
                            $place[
                                'city'
                            ]
                            ?? ''
                        )
                    ),

                    trim(
                        (string) (
                            $place[
                                'state'
                            ]
                            ?? ''
                        )
                    ),
                ],
                static fn (
                    string $value
                ): bool =>
                    $value !== ''
            )
        )
    );


?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    <?= $editUpdate
        ? 'Revise '
        : 'Update '
    ?><?= e(
        $place[
            'name'
        ]
    ) ?> | Llama Scout
  </title>


  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/account.css"
  >

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="update-place-shell">


  <a
    class="back-link"
    href="https://llamascout.com/place.php?place=<?= rawurlencode(
        $slug
    ) ?>"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to Place

  </a>


  <header class="update-place-intro">

    <h1>
      <?= $editUpdate
          ? 'Revise Place Update'
          : 'Update this Place'
      ?>
    </h1>

    <p>
      <strong>
        <?= e(
            $place[
                'name'
            ]
        ) ?>
      </strong>
    </p>

    <?php if (
        $placeLocation !== ''
    ): ?>

      <p class="update-place-location">
        <?= e(
            $placeLocation
        ) ?>
      </p>

    <?php endif; ?>


    <?php if (
        $editUpdate
        &&
        !empty(
            $editUpdate[
                'review_notes'
            ]
        )
    ): ?>

      <div
        class="
          update-place-message
          error
        "
      >

        <strong>
          Changes requested by moderation
        </strong>

        <?= e(
            $editUpdate[
                'review_notes'
            ]
        ) ?>

      </div>

    <?php endif; ?>


    <div class="update-place-note">

      <strong>
        Change only what you know.
      </strong>

      Select the specific fields that need updating. You do
      not need to answer fields you did not observe or test.
      Updates are reviewed before changing the public Place.

    </div>

  </header>


  <?php if (
      $error !== ''
  ): ?>

    <div
      class="
        update-place-message
        error
      "
    >
      <?= e(
          $error
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $success
  ): ?>

    <div
      class="
        update-place-message
        success
      "
    >

      <strong>
        <?= $editUpdate
            ? 'Changes resubmitted.'
            : 'Update submitted.'
        ?>
      </strong>

      <?= $editUpdate
          ? 'Your revised Place update is back in the moderation queue.'
          : 'Your proposed changes are now waiting for moderation.'
      ?>

      <?php if (
          $submittedUpdateId
      ): ?>

        Reference #<?= (int)
            $submittedUpdateId
        ?>.

      <?php endif; ?>

    </div>


    <a
      class="update-secondary-link"
      href="my-place-updates.php"
    >

      <i
        class="fa-solid fa-list"
        aria-hidden="true"
      ></i>

      My Place Updates

    </a>

    <br>


    <a
      class="update-secondary-link"
      href="https://llamascout.com/place.php?place=<?= rawurlencode(
          $slug
      ) ?>"
    >

      <i
        class="fa-solid fa-location-dot"
        aria-hidden="true"
      ></i>

      Return to this Place

    </a>


  <?php elseif (
      $hasOpenUpdate
  ): ?>

    <div
      class="
        update-place-message
        success
      "
    >

      <strong>
        You already have an open update for this Place.
      </strong>

      Wait for moderation or requested changes before
      submitting another structured update.

    </div>


    <a
      class="update-secondary-link"
      href="report-place.php?place=<?= rawurlencode(
          $slug
      ) ?>"
    >

      <i
        class="fa-solid fa-triangle-exclamation"
        aria-hidden="true"
      ></i>

      Report a separate safety or access problem

    </a>


  <?php else: ?>

    <form
      method="post"
      action="update-place.php"
    >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >

      <input
        type="hidden"
        name="place_slug"
        value="<?= e(
            $slug
        ) ?>"
      >


      <?php if (
          $editUpdate
      ): ?>

        <input
          type="hidden"
          name="edit_update_id"
          value="<?= $editUpdateId ?>"
        >

      <?php endif; ?>


      <section class="update-place-card">

        <h2>
          Contribution type
        </h2>

        <p>
          Submit the specific Place information that needs to
          change. Moderation will classify the contribution as
          a Place Update or Factual Correction during review so
          the same classification standard is applied
          consistently.
        </p>

      </section>


      <section class="update-place-card">

        <h2>
          What changed?
        </h2>

        <p>
          Open a section, check each field you want to change,
          then enter the new value. Unchecked fields are not
          part of your submission.
        </p>


        <?php foreach (
            $groupedFields as
            $groupLabel =>
            $fields
        ): ?>

          <details class="update-field-group">

            <summary>
              <?= e(
                  $groupLabel
              ) ?>
            </summary>


            <div class="update-field-list">


              <?php foreach (
                  $fields as
                  $path =>
                  $definition
              ): ?>

                <?php

                $type =
                    (string)
                    (
                        $definition[2]
                        ?? 'string'
                    );


                $token =
                    $fieldTokens[
                        $path
                    ];


                $selected =
                    in_array(
                        $token,
                        $selectedTokens,
                        true
                    );


                $postedValue =
                    $postedValues[
                        $token
                    ]
                    ?? '';


                $currentValue =
                    $currentValues[
                        $path
                    ]
                    ?? null;


                $longText =
                    in_array(
                        $path,
                        [
                            'description',
                            'sensorySummary',
                            'accessSummary',
                            'connectivity.starlinkNote',
                        ],
                        true
                    );

                ?>

                <div class="update-field-row">


                  <label class="update-field-copy">

                    <input
                      type="checkbox"
                      name="change[]"
                      value="<?= e(
                          $token
                      ) ?>"
                      data-update-toggle="<?= e(
                          $token
                      ) ?>"
                      <?= $selected
                          ? 'checked'
                          : ''
                      ?>
                    >

                    <span>

                      <strong>
                        <?= e(
                            update_form_label(
                                $path
                            )
                        ) ?>
                      </strong>

                      <span class="update-current">

                        Current:

                        <?= e(
                            update_form_display_value(
                                $currentValue,
                                $type
                            )
                        ) ?>

                      </span>

                    </span>

                  </label>


                  <div class="update-field-input">


                    <?php if (
                        $type ===
                        'bool'
                    ): ?>

                      <select
                        name="value[<?= e(
                            $token
                        ) ?>]"
                        data-update-input="<?= e(
                            $token
                        ) ?>"
                        <?= !$selected
                            ? 'disabled'
                            : ''
                        ?>
                      >

                        <option value="__NULL__">
                          Unknown / clear value
                        </option>

                        <option
                          value="1"
                          <?= (string)
                              $postedValue
                              === '1'
                                  ? 'selected'
                                  : ''
                          ?>
                        >
                          Yes
                        </option>

                        <option
                          value="0"
                          <?= (string)
                              $postedValue
                              === '0'
                                  ? 'selected'
                                  : ''
                          ?>
                        >
                          No
                        </option>

                      </select>


                    <?php elseif (
                        $type ===
                        'int'
                        ||
                        $type ===
                        'float'
                    ): ?>

                      <input
                        type="number"
                        <?= $type ===
                            'float'
                                ? 'step="any"'
                                : 'step="1"'
                        ?>
                        name="value[<?= e(
                            $token
                        ) ?>]"
                        value="<?= e(
                            $postedValue
                        ) ?>"
                        placeholder="New value"
                        data-update-input="<?= e(
                            $token
                        ) ?>"
                        <?= !$selected
                            ? 'disabled'
                            : ''
                        ?>
                      >


                    <?php elseif (
                        $longText
                    ): ?>

                      <textarea
                        name="value[<?= e(
                            $token
                        ) ?>]"
                        placeholder="New value"
                        data-update-input="<?= e(
                            $token
                        ) ?>"
                        <?= !$selected
                            ? 'disabled'
                            : ''
                        ?>
                      ><?= e(
                          $postedValue
                      ) ?></textarea>


                    <?php else: ?>

                      <input
                        type="text"
                        name="value[<?= e(
                            $token
                        ) ?>]"
                        value="<?= e(
                            $postedValue
                        ) ?>"
                        placeholder="New value"
                        data-update-input="<?= e(
                            $token
                        ) ?>"
                        <?= !$selected
                            ? 'disabled'
                            : ''
                        ?>
                      >

                    <?php endif; ?>


                  </div>


                </div>

              <?php endforeach; ?>


            </div>

          </details>

        <?php endforeach; ?>


      </section>


      <section class="update-place-card">

        <h2>
          Visit and notes
        </h2>

        <p>
          A visit date is optional for community corrections.
          If you personally visited the Place for these
          observations, include the visit date.
        </p>


        <div class="update-meta-grid">


          <label>

            Visit date

            <input
              type="date"
              name="visited_date"
              value="<?= e(
                  $visitedDate
              ) ?>"
              max="<?= e(
                  date(
                      'Y-m-d'
                  )
              ) ?>"
            >

          </label>


          <label>

            Notes for the moderator

            <textarea
              name="contributor_notes"
              maxlength="3000"
              placeholder="Anything that helps explain the change, what you observed, or why the existing information is incorrect."
            ><?= e(
                $contributorNotes
            ) ?></textarea>

          </label>


        </div>


        <button
          class="update-submit"
          type="submit"
        >

          <i
            class="fa-solid fa-paper-plane"
            aria-hidden="true"
          ></i>

          <?= $editUpdate
              ? 'Resubmit Place Update'
              : 'Submit Place Update'
          ?>

        </button>

      </section>


    </form>


    <a
      class="update-secondary-link"
      href="report-place.php?place=<?= rawurlencode(
          $slug
      ) ?>"
    >

      <i
        class="fa-solid fa-triangle-exclamation"
        aria-hidden="true"
      ></i>

      Is this a closure, safety issue, private property
      concern, or urgent access problem? Flag a concern
      instead.

    </a>


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>

<script
  src="https://llamascout.com/js/update-place-photos.js"
></script>
    
<script>

document
  .querySelectorAll(
    "[data-update-toggle]"
  )
  .forEach(
    (toggle) => {

      const token =
        toggle.getAttribute(
          "data-update-toggle"
        );


      const input =
        document.querySelector(
          `[data-update-input="${token}"]`
        );


      if (!input) {
        return;
      }


      const sync =
        () => {

          input.disabled =
            !toggle.checked;


          if (
            toggle.checked
          ) {

            input.focus();

          }

        };


      toggle.addEventListener(
        "change",
        sync
      );

    }
  );

</script>


</body>

</html>
