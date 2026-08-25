<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   LOCATION LOOKUP API
   api/location-lookup.php

   Accepts latitude + longitude from the browser and returns:

   - city
   - state
   - county
   - elevation
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/location-lookup.php';


header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store'
);



/* =========================================================
   RESPONSE HELPERS
   ========================================================= */

function location_api_error(
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



function location_api_success(
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
   REQUIRE SIGNED-IN USER

   Add Place already requires an account, so we keep this
   lookup behind the same authenticated experience.
   ========================================================= */

start_llama_session();


$user =
    current_user();


if (
    !$user
) {

    location_api_error(
        'You must be signed in to use location lookup.',
        401
    );
}



/* =========================================================
   REQUEST
   ========================================================= */

$latitude =
    $_GET[
        'lat'
    ]
    ?? null;


$longitude =
    $_GET[
        'lon'
    ]
    ?? null;



if (
    $latitude === null
    ||
    $longitude === null
) {

    location_api_error(
        'Latitude and longitude are required.',
        400
    );
}



if (
    !location_coordinates_valid(
        $latitude,
        $longitude
    )
) {

    location_api_error(
        'The coordinates were invalid.',
        422
    );
}



/* =========================================================
   LOOKUP
   ========================================================= */

try {

    $location =
        location_lookup(
            (float)
            $latitude,

            (float)
            $longitude
        );


    location_api_success(
        [
            'latitude' =>
                $location[
                    'latitude'
                ],

            'longitude' =>
                $location[
                    'longitude'
                ],

            'locality' =>
                $location[
                    'locality'
                ],

            'state' =>
                $location[
                    'state'
                ],

            'county' =>
                $location[
                    'county'
                ],

            'elevationFeet' =>
                $location[
                    'elevationFeet'
                ],

            'displayName' =>
                $location[
                    'displayName'
                ]
        ]
    );


} catch (
    InvalidArgumentException $error
) {

    location_api_error(
        $error->getMessage(),
        422
    );


} catch (
    RuntimeException $error
) {

    error_log(
        'Llama Scout location lookup error: '
        .
        $error->getMessage()
    );


    location_api_error(
        'Location details could not be looked up right now.',
        503
    );


} catch (
    Throwable $error
) {

    error_log(
        'Llama Scout location lookup API error: '
        .
        $error->getMessage()
    );


    location_api_error(
        'Location lookup failed.',
        500
    );
}
