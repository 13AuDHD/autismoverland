<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   WEATHER API
   api/weather.php

   Visitor / Free:
   Returns nearby city weather.

   Member / Scout / Admin / Owner:
   Returns exact campsite weather using the stored
   coordinates and elevation.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/place-access.php';

require_once
    dirname(__DIR__)
    . '/app/weather.php';


header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store'
);



/* =========================================================
   RESPONSE HELPERS
   ========================================================= */

function weather_api_error(
    string $message,
    int $status = 400
): never {

    http_response_code(
        $status
    );


    echo json_encode(
        [
            'ok' =>
                false,

            'error' =>
                $message
        ],
        JSON_UNESCAPED_SLASHES
    );


    exit;
}



function weather_api_success(
    array $data
): never {

    echo json_encode(
        [
            'ok' =>
                true,

            'data' =>
                $data
        ],
        JSON_UNESCAPED_SLASHES
    );


    exit;
}



/* =========================================================
   REQUEST
   ========================================================= */

$requestedPlace =
    trim(
        (string) (
            $_GET[
                'place'
            ]
            ??
            ''
        )
    );


if (
    $requestedPlace === ''
) {

    weather_api_error(
        'A place is required.',
        400
    );
}



/* =========================================================
   LOAD PLACE

   We query the database directly here rather than trusting
   coordinates, city names, or elevation sent by the browser.

   The browser supplies only a place slug.
   ========================================================= */

try {

    $db =
        db();


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                slug,
                name,
                status,
                latitude,
                longitude,
                elevation_feet,
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


    $stmt->execute([
        $requestedPlace
    ]);


    $place =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$place
    ) {

        weather_api_error(
            'Place not found.',
            404
        );
    }



    /* =====================================================
       ACCESS LEVEL

       Uses the exact same access logic as the rest of the
       Llama Scout place system.
       ===================================================== */

    $accessLevel =
        place_access_level();



    /* =====================================================
       MEMBER WEATHER

       Paid members, complimentary members, active Scouts,
       Admins, and Owners receive weather for the exact
       campsite coordinates and stored elevation.
       ===================================================== */

    if (
        $accessLevel ===
        'member'
    ) {

        $latitude =
            $place[
                'latitude'
            ]
            ?? null;


        $longitude =
            $place[
                'longitude'
            ]
            ?? null;


        $elevationFeet =
            $place[
                'elevation_feet'
            ]
            ?? null;


        if (
            !is_numeric(
                $latitude
            )
            ||
            !is_numeric(
                $longitude
            )
        ) {

            weather_api_error(
                'Exact weather is unavailable for this place.',
                422
            );
        }


        $weather =
            weather_fetch_campsite_forecast(
                (float)
                $latitude,

                (float)
                $longitude,

                is_numeric(
                    $elevationFeet
                )
                    ? (float)
                      $elevationFeet
                    : null,

                7
            );


        weather_api_success(
            [
                'accessLevel' =>
                    'member',

                'forecastType' =>
                    'campsite',

                'label' =>
                    'Campsite Weather',

                'place' =>
                    [
                        'slug' =>
                            $place[
                                'slug'
                            ],

                        'name' =>
                            $place[
                                'name'
                            ]
                    ],

                'weather' =>
                    $weather
            ]
        );
    }



    /* =====================================================
       VISITOR / FREE WEATHER

       Both access levels receive the same nearby city
       forecast.

       Exact campsite coordinates and elevation never leave
       this branch and are never passed into the weather
       service.
       ===================================================== */

    $city =
        trim(
            (string) (
                $place[
                    'city'
                ]
                ??
                ''
            )
        );


    $state =
        trim(
            (string) (
                $place[
                    'state'
                ]
                ??
                ''
            )
        );


    if (
        $city === ''
        ||
        $state === ''
    ) {

        weather_api_error(
            'Nearby weather is unavailable for this place.',
            422
        );
    }


    $weather =
        weather_fetch_city_forecast(
            $city,
            $state,
            7
        );


    weather_api_success(
        [
            'accessLevel' =>
                $accessLevel,

            'forecastType' =>
                'city',

            'label' =>
                'Local Weather',

            'place' =>
                [
                    'slug' =>
                        $place[
                            'slug'
                        ],

                    'name' =>
                        $place[
                            'name'
                        ]
                ],

            'weatherLocation' =>
                [
                    'city' =>
                        $city,

                    'state' =>
                        $state
                ],

            'weather' =>
                $weather
        ]
    );


} catch (
    RuntimeException $error
) {

    error_log(
        'Llama Scout weather error: '
        .
        $error->getMessage()
    );


    weather_api_error(
        'Weather is temporarily unavailable.',
        503
    );


} catch (
    Throwable $error
) {

    error_log(
        'Llama Scout weather API error: '
        .
        $error->getMessage()
    );


    weather_api_error(
        'Weather is temporarily unavailable.',
        500
    );
}
