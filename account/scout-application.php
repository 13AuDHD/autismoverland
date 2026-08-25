<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_verified_email();
start_llama_session();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user['id'];


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


function posted(
    string $key,
    mixed $fallback = ''
): mixed {

    return $_POST[
        $key
    ]
    ??
    $fallback;

}


/* =========================================================
   LOAD SCOUT PROFILE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            user_id,
            status,
            invited_at,
            application_started_at,
            application_submitted_at,
            training_started_at,
            training_completed_at,
            approved_at,
            scout_started_at,
            active_through

        FROM scout_profiles

        WHERE user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$scoutProfile =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$scoutProfile
) {

    http_response_code(
        404
    );


    exit(
        'No Scout invitation was found for this account.'
    );

}


$scoutProfileId =
    (int)
    $scoutProfile[
        'id'
    ];


$status =
    (string)
    $scoutProfile[
        'status'
    ];


/* =========================================================
   ALLOWED STATES
   ========================================================= */

$applicationStates = [
    'application_started',
    'application_submitted',
    'training',
    'pending_approval',
];


if (
    !in_array(
        $status,
        $applicationStates,
        true
    )
) {

    if (
        $status ===
        'invited'
    ) {

        header(
            'Location: scout-invite.php'
        );


        exit;

    }


    if (
        $status ===
        'active'
    ) {

        header(
            'Location: /'
        );


        exit;

    }


    http_response_code(
        403
    );


    exit(
        'Your Scout application is not currently available.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'scout_application_csrf'
        ]
    )
) {

    $_SESSION[
        'scout_application_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'scout_application_csrf'
    ];


/* =========================================================
   LOAD EXISTING APPLICATION
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            scout_profile_id,
            user_id,
            legal_name,
            address_line_1,
            address_line_2,
            city,
            state_region,
            postal_code,
            country,
            phone,
            why_scout,
            travel_experience,
            field_experience,
            accessibility_experience,
            sensory_experience,
            agrees_accuracy,
            agrees_safety,
            agrees_conduct,
            submitted_at,
            reviewed_at,
            reviewed_by,
            review_notes,
            created_at,
            updated_at

        FROM scout_applications

        WHERE scout_profile_id = ?
          AND user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $scoutProfileId,
    $userId
]);


$application =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


/* =========================================================
   FORM VALUES
   ========================================================= */

$legalName =
    (string) (
        $application[
            'legal_name'
        ]
        ??
        ''
    );


$address1 =
    (string) (
        $application[
            'address_line_1'
        ]
        ??
        ''
    );


$address2 =
    (string) (
        $application[
            'address_line_2'
        ]
        ??
        ''
    );


$city =
    (string) (
        $application[
            'city'
        ]
        ??
        ''
    );


$stateRegion =
    (string) (
        $application[
            'state_region'
        ]
        ??
        ''
    );


$postalCode =
    (string) (
        $application[
            'postal_code'
        ]
        ??
        ''
    );


$country =
    (string) (
        $application[
            'country'
        ]
        ??
        'United States'
    );


$phone =
    (string) (
        $application[
            'phone'
        ]
        ??
        ''
    );


$whyScout =
    (string) (
        $application[
            'why_scout'
        ]
        ??
        ''
    );


$travelExperience =
    (string) (
        $application[
            'travel_experience'
        ]
        ??
        ''
    );


$fieldExperience =
    (string) (
        $application[
            'field_experience'
        ]
        ??
        ''
    );


$accessibilityExperience =
    (string) (
        $application[
            'accessibility_experience'
        ]
        ??
        ''
    );


$sensoryExperience =
    (string) (
        $application[
            'sensory_experience'
        ]
        ??
        ''
    );


$agreesAccuracy =
    !empty(
        $application[
            'agrees_accuracy'
        ]
    );


$agreesSafety =
    !empty(
        $application[
            'agrees_safety'
        ]
    );


$agreesConduct =
    !empty(
        $application[
            'agrees_conduct'
        ]
    );


/* =========================================================
   SUBMIT
   ========================================================= */

$errors =
    [];


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

        if (
        $status !==
        'application_started'
    ) {

        http_response_code(
            409
        );


        exit(
            'Your About You information is no longer open for editing.'
        );
    }

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

        $errors[] =
            'Your session could not be verified. Reload the page and try again.';

    }


    $legalName =
        trim(
            (string)
            posted(
                'legal_name'
            )
        );


    $address1 =
        trim(
            (string)
            posted(
                'address_line_1'
            )
        );


    $address2 =
        trim(
            (string)
            posted(
                'address_line_2'
            )
        );


    $city =
        trim(
            (string)
            posted(
                'city'
            )
        );


    $stateRegion =
        trim(
            (string)
            posted(
                'state_region'
            )
        );


    $postalCode =
        trim(
            (string)
            posted(
                'postal_code'
            )
        );


    $country =
        trim(
            (string)
            posted(
                'country',
                'United States'
            )
        );


    $phone =
        trim(
            (string)
            posted(
                'phone'
            )
        );


    $whyScout =
        trim(
            (string)
            posted(
                'why_scout'
            )
        );


    $travelExperience =
        trim(
            (string)
            posted(
                'travel_experience'
            )
        );


    $fieldExperience =
        trim(
            (string)
            posted(
                'field_experience'
            )
        );


    $accessibilityExperience =
        trim(
            (string)
            posted(
                'accessibility_experience'
            )
        );


    $sensoryExperience =
        trim(
            (string)
            posted(
                'sensory_experience'
            )
        );


    $agreesAccuracy =
        isset(
            $_POST[
                'agrees_accuracy'
            ]
        );


    $agreesSafety =
        isset(
            $_POST[
                'agrees_safety'
            ]
        );


    $agreesConduct =
        isset(
            $_POST[
                'agrees_conduct'
            ]
        );


    /* =====================================================
       VALIDATION
       ===================================================== */

    if (
        $legalName === ''
    ) {

        $errors[] =
            'Enter your legal name.';

    }


    if (
        $address1 === ''
    ) {

        $errors[] =
            'Enter your mailing address.';

    }


    if (
        $city === ''
    ) {

        $errors[] =
            'Enter your city.';

    }


    if (
        $stateRegion === ''
    ) {

        $errors[] =
            'Enter your state, province, or region.';

    }


    if (
        $postalCode === ''
    ) {

        $errors[] =
            'Enter your ZIP or postal code.';

    }


    if (
        $country === ''
    ) {

        $errors[] =
            'Enter your country.';

    }


    if (
        !$agreesAccuracy
    ) {

        $errors[] =
            'Confirm that you will provide Scout information as accurately as you reasonably can.';

    }


    if (
        !$agreesSafety
    ) {

        $errors[] =
            'Confirm that you understand safety comes before completing a Scout Report.';

    }


    if (
        !$agreesConduct
    ) {

        $errors[] =
            'Confirm that you agree to follow the Scout community expectations.';

    }


    /* =====================================================
       LENGTH LIMITS
       ===================================================== */

    if (
        mb_strlen(
            $legalName
        ) > 150
    ) {

        $errors[] =
            'Legal name is too long.';

    }


    if (
        mb_strlen(
            $address1
        ) > 150
        ||
        mb_strlen(
            $address2
        ) > 150
    ) {

        $errors[] =
            'One of the address fields is too long.';

    }


    if (
        mb_strlen(
            $city
        ) > 100
        ||
        mb_strlen(
            $stateRegion
        ) > 100
        ||
        mb_strlen(
            $country
        ) > 100
    ) {

        $errors[] =
            'One of the location fields is too long.';

    }


    if (
        mb_strlen(
            $postalCode
        ) > 30
    ) {

        $errors[] =
            'Postal code is too long.';

    }


    if (
        mb_strlen(
            $phone
        ) > 40
    ) {

        $errors[] =
            'Phone number is too long.';

    }


    /* =====================================================
       SAVE
       ===================================================== */

    if (
        !$errors
    ) {

        try {

            $db->beginTransaction();


            if (
                !empty(
                    $application[
                        'id'
                    ]
                )
            ) {

                $stmt =
                    $db->prepare(
                        '
                        UPDATE scout_applications

                        SET
                            legal_name = ?,
                            address_line_1 = ?,
                            address_line_2 = ?,
                            city = ?,
                            state_region = ?,
                            postal_code = ?,
                            country = ?,
                            phone = ?,
                            why_scout = ?,
                            travel_experience = ?,
                            field_experience = ?,
                            accessibility_experience = ?,
                            sensory_experience = ?,
                            agrees_accuracy = ?,
                            agrees_safety = ?,
                            agrees_conduct = ?,
                            submitted_at =
                                COALESCE(
                                    submitted_at,
                                    CURRENT_TIMESTAMP
                                ),
                            
                            reviewed_at =
                                NULL,
                            
                            reviewed_by =
                                NULL,
                            
                            review_notes =
                                NULL,
                            
                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id = ?
                          AND scout_profile_id = ?
                          AND user_id = ?
                        '
                    );


                $stmt->execute([
                    $legalName,
                    $address1,
                    $address2 !== ''
                        ? $address2
                        : null,
                    $city,
                    $stateRegion,
                    $postalCode,
                    $country,
                    $phone !== ''
                        ? $phone
                        : null,
                    $whyScout !== ''
                        ? $whyScout
                        : null,
                    $travelExperience !== ''
                        ? $travelExperience
                        : null,
                    $fieldExperience !== ''
                        ? $fieldExperience
                        : null,
                    $accessibilityExperience !== ''
                        ? $accessibilityExperience
                        : null,
                    $sensoryExperience !== ''
                        ? $sensoryExperience
                        : null,
                    $agreesAccuracy
                        ? 1
                        : 0,
                    $agreesSafety
                        ? 1
                        : 0,
                    $agreesConduct
                        ? 1
                        : 0,
                    (int)
                    $application[
                        'id'
                    ],
                    $scoutProfileId,
                    $userId,
                ]);


            } else {

                $stmt =
                    $db->prepare(
                        '
                        INSERT INTO scout_applications
                        (
                            scout_profile_id,
                            user_id,
                            legal_name,
                            address_line_1,
                            address_line_2,
                            city,
                            state_region,
                            postal_code,
                            country,
                            phone,
                            why_scout,
                            travel_experience,
                            field_experience,
                            accessibility_experience,
                            sensory_experience,
                            agrees_accuracy,
                            agrees_safety,
                            agrees_conduct,
                            submitted_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            CURRENT_TIMESTAMP
                        )
                        '
                    );


                $stmt->execute([
                    $scoutProfileId,
                    $userId,
                    $legalName,
                    $address1,
                    $address2 !== ''
                        ? $address2
                        : null,
                    $city,
                    $stateRegion,
                    $postalCode,
                    $country,
                    $phone !== ''
                        ? $phone
                        : null,
                    $whyScout !== ''
                        ? $whyScout
                        : null,
                    $travelExperience !== ''
                        ? $travelExperience
                        : null,
                    $fieldExperience !== ''
                        ? $fieldExperience
                        : null,
                    $accessibilityExperience !== ''
                        ? $accessibilityExperience
                        : null,
                    $sensoryExperience !== ''
                        ? $sensoryExperience
                        : null,
                    $agreesAccuracy
                        ? 1
                        : 0,
                    $agreesSafety
                        ? 1
                        : 0,
                    $agreesConduct
                        ? 1
                        : 0,
                ]);

            }


            $stmt =
                $db->prepare(
                    '
                    UPDATE scout_profiles

                    SET
                        status =
                            \'application_submitted\',

                        application_submitted_at =
                            COALESCE(
                                application_submitted_at,
                                CURRENT_TIMESTAMP
                            ),

                        updated_at =
                            CURRENT_TIMESTAMP

                        WHERE id = ?
                          AND user_id = ?
                          AND status = 'application_started'
                    '
                );


            $stmt->execute([
                $scoutProfileId,
                $userId
            ]);

            if (
                $stmt->rowCount()
                !==
                1
            ) {
            
                throw new RuntimeException(
                    'Scout onboarding state changed before the application could be submitted.'
                );
            }


            $db->commit();


            header(
                'Location: scout-training.php'
            );


            exit;


        } catch (
            Throwable $exception
        ) {

            if (
                $db->inTransaction()
            ) {

                $db->rollBack();

            }


            error_log(
                'Llama Scout Scout application error: '
                .
                $exception
                    ->getMessage()
            );


            $errors[] =
                'Your information could not be saved. Please try again.';

        }

    }

}


/* =========================================================
   NAME
   ========================================================= */

$displayName =
    trim(
        (string) (
            $user[
                'display_name'
            ]
            ?:
            $user[
                'username'
            ]
            ?:
            $user[
                'email'
            ]
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

  <title>
    Tell Us About You | Llama Scout
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >


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


<main class="scout-about-page">


  <div class="scout-about-shell">


    <!-- ===================================================
         PROGRESS
         =================================================== -->

    <div
      class="scout-progress"
      aria-label="Scout onboarding progress"
    >

      <div class="scout-progress-step done">
        Invitation
      </div>

      <div class="scout-progress-step active">
        About You
      </div>

      <div class="scout-progress-step">
        Training
      </div>

      <div class="scout-progress-step">
        Review
      </div>

      <div class="scout-progress-step">
        Scout
      </div>

    </div>


    <!-- ===================================================
         HERO
         =================================================== -->

    <section class="scout-about-hero">


      <p class="scout-about-eyebrow">

        <i
          class="fa-solid fa-person-hiking"
          aria-hidden="true"
        ></i>

        Step 2 of 5

      </p>


      <h1>
        Tell us a little more about you.
      </h1>


      <p>

        <?= e($displayName) ?>, Scout Reports are better when
        they come from people with different vehicles,
        experiences, access needs, sensory needs, travel
        styles, and ways of noticing the world.

        There aren't right answers here. We just want to know
        more about the person behind the reports.

      </p>


    </section>


    <?php if ($errors): ?>


      <div class="scout-errors">

        <strong>
          A few things need attention:
        </strong>

        <ul>

          <?php foreach ($errors as $error): ?>

            <li>
              <?= e($error) ?>
            </li>

          <?php endforeach; ?>

        </ul>

      </div>


    <?php endif; ?>


    <!-- ===================================================
         FORM
         =================================================== -->

    <form
      class="scout-about-form"
      method="post"
      action="scout-application.php"
    >


      <input
        type="hidden"
        name="csrf_token"
        value="<?= e($csrfToken) ?>"
      >


      <!-- =================================================
           CONTACT
           ================================================= -->

      <section class="scout-form-card">


        <h2>
          The boring-but-important stuff
        </h2>


        <p>

          We need real contact information for Scouts because
          this is a trusted contributor role.

          This information is private and is not displayed on
          your public profile.

        </p>


        <div class="scout-private-note">

          <i
            class="fa-solid fa-lock"
            aria-hidden="true"
          ></i>

          <span>

            <strong>
              Private information
            </strong>

            Your legal name, mailing address, and phone number
            are visible only to authorized Llama Scout
            administrators.

          </span>

        </div>


        <div class="scout-form-grid">


          <div class="scout-field wide">

            <label for="legal-name">
              Legal name *
            </label>

            <input
              id="legal-name"
              name="legal_name"
              type="text"
              maxlength="150"
              autocomplete="name"
              value="<?= e($legalName) ?>"
              required
            >

          </div>


          <div class="scout-field wide">

            <label for="address-line-1">
              Mailing address *
            </label>

            <input
              id="address-line-1"
              name="address_line_1"
              type="text"
              maxlength="150"
              autocomplete="address-line1"
              value="<?= e($address1) ?>"
              required
            >

          </div>


          <div class="scout-field wide">

            <label for="address-line-2">
              Address line 2
            </label>

            <input
              id="address-line-2"
              name="address_line_2"
              type="text"
              maxlength="150"
              autocomplete="address-line2"
              value="<?= e($address2) ?>"
            >

          </div>


          <div class="scout-field">

            <label for="city">
              City *
            </label>

            <input
              id="city"
              name="city"
              type="text"
              maxlength="100"
              autocomplete="address-level2"
              value="<?= e($city) ?>"
              required
            >

          </div>


          <div class="scout-field">

            <label for="state-region">
              State / Province / Region *
            </label>

            <input
              id="state-region"
              name="state_region"
              type="text"
              maxlength="100"
              autocomplete="address-level1"
              value="<?= e($stateRegion) ?>"
              required
            >

          </div>


          <div class="scout-field">

            <label for="postal-code">
              ZIP / Postal code *
            </label>

            <input
              id="postal-code"
              name="postal_code"
              type="text"
              maxlength="30"
              autocomplete="postal-code"
              value="<?= e($postalCode) ?>"
              required
            >

          </div>


          <div class="scout-field">

            <label for="country">
              Country *
            </label>

            <input
              id="country"
              name="country"
              type="text"
              maxlength="100"
              autocomplete="country-name"
              value="<?= e($country) ?>"
              required
            >

          </div>


          <div class="scout-field">

            <label for="phone">
              Phone
            </label>

            <input
              id="phone"
              name="phone"
              type="tel"
              maxlength="40"
              autocomplete="tel"
              value="<?= e($phone) ?>"
            >

            <small>
              Optional, but useful if we ever need to reach
              you about your Scout account.
            </small>

          </div>


        </div>


      </section>


      <!-- =================================================
           PERSONAL
           ================================================= -->

      <section class="scout-form-card">


        <h2>
          Now the interesting part
        </h2>


        <p>

          Short answers are fine. Long answers are fine.
          Weird travel stories are probably fine too.

          This is context, not a test.

        </p>


        <div class="scout-question">


          <h3>
            What made you interested in becoming a Scout?
          </h3>


          <p>

            Maybe you like documenting places, helping other
            travelers, exploring backroads, obsessively
            collecting details, or something entirely
            different.

          </p>


          <textarea
            name="why_scout"
            maxlength="5000"
            placeholder="Tell us what caught your interest..."
          ><?= e($whyScout) ?></textarea>


        </div>


        <div class="scout-question">


          <h3>
            What does travel usually look like for you?
          </h3>


          <p>

            Tell us about road trips, camping, overlanding,
            RV travel, backpacking, day trips, vanlife,
            car camping, or however you tend to move around.

          </p>


          <textarea
            name="travel_experience"
            maxlength="5000"
            placeholder="There isn't a required amount of experience..."
          ><?= e($travelExperience) ?></textarea>


        </div>


        <div class="scout-question">


          <h3>
            What kinds of things do you naturally notice at a place?
          </h3>


          <p>

            Roads, site size, noise, privacy, bathrooms,
            trees, cell signal, crowds, accessibility,
            wildlife, sketchy turns... whatever your brain
            tends to catalog.

          </p>


          <textarea
            name="field_experience"
            maxlength="5000"
            placeholder="What tends to catch your attention?"
          ><?= e($fieldExperience) ?></textarea>


        </div>


      </section>


      <!-- =================================================
           ACCESS & SENSORY
           ================================================= -->

      <section class="scout-form-card">


        <h2>
          Your perspective matters
        </h2>


        <p>

          Llama Scout works because different people notice
          different barriers and comforts.

          You don't need personal experience with disability
          or sensory differences to become a Scout.

        </p>


        <div class="scout-question">


          <h3>
            Do accessibility needs influence how you evaluate places?
          </h3>


          <p>

            This could include mobility, walking distance,
            terrain, bathroom access, steps, parking, vehicle
            access, or experiences helping somebody else with
            those needs.

          </p>


          <textarea
            name="accessibility_experience"
            maxlength="5000"
            placeholder="Optional..."
          ><?= e($accessibilityExperience) ?></textarea>


        </div>


        <div class="scout-question">


          <h3>
            Do sensory conditions affect how you experience places?
          </h3>


          <p>

            Noise, crowds, smells, lighting, traffic,
            generators, privacy, predictability, temperature,
            or anything else you tend to notice.

          </p>


          <textarea
            name="sensory_experience"
            maxlength="5000"
            placeholder="Optional..."
          ><?= e($sensoryExperience) ?></textarea>


        </div>


      </section>


      <!-- =================================================
           AGREEMENTS
           ================================================= -->

      <section class="scout-form-card">


        <h2>
          Three things we do need you to agree to
        </h2>


        <p>

          Scout Reports don't have to be perfect.

          They do need to be thoughtful, honest, and made
          without putting yourself or somebody else in danger.

        </p>


        <div class="scout-agreements">


          <div class="scout-agreement">

            <input
              id="agrees-accuracy"
              name="agrees_accuracy"
              type="checkbox"
              value="1"
              <?= $agreesAccuracy
                  ? 'checked'
                  : ''
              ?>
              required
            >

            <label for="agrees-accuracy">

              <strong>
                Accuracy
              </strong>

              I will provide Scout information as accurately
              as I reasonably can and clearly mark things I
              don't know instead of guessing.

            </label>

          </div>


          <div class="scout-agreement">

            <input
              id="agrees-safety"
              name="agrees_safety"
              type="checkbox"
              value="1"
              <?= $agreesSafety
                  ? 'checked'
                  : ''
              ?>
              required
            >

            <label for="agrees-safety">

              <strong>
                Safety
              </strong>

              I understand that gathering information for
              Llama Scout is never more important than my
              safety, another person's safety, or following
              closures and local rules.

            </label>

          </div>


          <div class="scout-agreement">

            <input
              id="agrees-conduct"
              name="agrees_conduct"
              type="checkbox"
              value="1"
              <?= $agreesConduct
                  ? 'checked'
                  : ''
              ?>
              required
            >

            <label for="agrees-conduct">

              <strong>
                Community conduct
              </strong>

              I will use Scout access responsibly, respect
              private and sensitive information, and
              contribute in good faith.

            </label>

          </div>


        </div>


      </section>


      <!-- =================================================
           SUBMIT
           ================================================= -->

      <div class="scout-submit-wrap">


        <p class="scout-submit-copy">

          When you continue, this information will be saved
          and you'll move to the Scout orientation.

          Completing this page does not activate Scout status
          yet.

        </p>


        <button
          class="scout-submit-button"
          type="submit"
        >

          Continue to Training

          <i
            class="fa-solid fa-arrow-right"
            aria-hidden="true"
          ></i>

        </button>


      </div>


    </form>


  </div>


</main>


<script src="https://llamascout.com/js/header.js"></script>


</body>

</html>
