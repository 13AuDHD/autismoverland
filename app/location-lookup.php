<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   LOCATION LOOKUP SERVICE

   Takes exact coordinates and attempts to determine:

   - nearest city / town
   - state
   - county
   - elevation

   This file does not read browser location itself.
   The browser location will be passed through our API later.
   ========================================================= */


/* =========================================================
   ENDPOINTS
   ========================================================= */

const LLAMA_LOCATION_REVERSE_URL =
    'https://nominatim.openstreetmap.org/reverse';

const LLAMA_LOCATION_ELEVATION_URL =
    'https://api.open-meteo.com/v1/elevation';



/* =========================================================
   HTTP GET
   ========================================================= */

function location_http_get(
    string $url,
    array $parameters = []
): array {

    if ($parameters) {

        $query =
            http_build_query(
                $parameters,
                '',
                '&',
                PHP_QUERY_RFC3986
            );


        $url .=
            (
                str_contains(
                    $url,
                    '?'
                )
                    ? '&'
                    : '?'
            )
            .
            $query;
    }


    $curl =
        curl_init();


    if (
        $curl === false
    ) {

        throw new RuntimeException(
            'Could not initialize location request.'
        );
    }


    curl_setopt_array(
        $curl,
        [

            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                5,

            CURLOPT_TIMEOUT =>
                10,

            /*
             * Nominatim requires an identifiable
             * application User-Agent.
             */

            CURLOPT_USERAGENT =>
                'LlamaScout/1.0 (+https://llamascout.com)',

            CURLOPT_HTTPHEADER =>
                [
                    'Accept: application/json',
                    'Accept-Language: en-US,en;q=0.9'
                ]

        ]
    );


    $response =
        curl_exec(
            $curl
        );


    $status =
        (int)
        curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );


    $error =
        curl_error(
            $curl
        );


    curl_close(
        $curl
    );


    if (
        $response === false
        ||
        $status < 200
        ||
        $status >= 300
    ) {

        throw new RuntimeException(
            $error !== ''
                ? $error
                : 'Location service returned HTTP '
                  . $status
                  . '.'
        );
    }


    $decoded =
        json_decode(
            $response,
            true
        );


    if (
        !is_array(
            $decoded
        )
    ) {

        throw new RuntimeException(
            'Location service returned invalid JSON.'
        );
    }


    return
        $decoded;
}



/* =========================================================
   VALIDATE COORDINATES
   ========================================================= */

function location_coordinates_valid(
    mixed $latitude,
    mixed $longitude
): bool {

    if (
        !is_numeric(
            $latitude
        )
        ||
        !is_numeric(
            $longitude
        )
    ) {

        return false;
    }


    $latitude =
        (float)
        $latitude;


    $longitude =
        (float)
        $longitude;


    return (
        $latitude >= -90
        &&
        $latitude <= 90
        &&
        $longitude >= -180
        &&
        $longitude <= 180
    );
}



/* =========================================================
   REVERSE GEOCODING
   ========================================================= */

function location_reverse_geocode(
    float $latitude,
    float $longitude
): array {

    $data =
        location_http_get(
            LLAMA_LOCATION_REVERSE_URL,
            [

                'lat' =>
                    $latitude,

                'lon' =>
                    $longitude,

                'format' =>
                    'jsonv2',

                'addressdetails' =>
                    1,

                'zoom' =>
                    10

            ]
        );


    $address =
        $data[
            'address'
        ]
        ?? [];


    if (
        !is_array(
            $address
        )
    ) {

        $address = [];
    }


    /*
     * Remote campsites may resolve as a city, town,
     * village, hamlet, municipality, or locality.
     *
     * Prefer the larger settlement labels first.
     */

    $city =
        location_first_string(
            $address,
            [
                'city',
                'town',
                'village',
                'municipality',
                'hamlet',
                'locality'
            ]
        );


    $state =
        location_first_string(
            $address,
            [
                'state'
            ]
        );


    $county =
        location_first_string(
            $address,
            [
                'county'
            ]
        );


    return [

        'city' =>
            $city,

        'state' =>
            $state,

        'county' =>
            $county,

        'displayName' =>
            isset(
                $data[
                    'display_name'
                ]
            )
                ? trim(
                    (string)
                    $data[
                        'display_name'
                    ]
                )
                : null

    ];
}



/* =========================================================
   ELEVATION
   ========================================================= */

function location_lookup_elevation_feet(
    float $latitude,
    float $longitude
): ?int {

    $data =
        location_http_get(
            LLAMA_LOCATION_ELEVATION_URL,
            [

                'latitude' =>
                    $latitude,

                'longitude' =>
                    $longitude

            ]
        );


    $elevations =
        $data[
            'elevation'
        ]
        ?? null;


    if (
        !is_array(
            $elevations
        )
        ||
        !isset(
            $elevations[
                0
            ]
        )
        ||
        !is_numeric(
            $elevations[
                0
            ]
        )
    ) {

        return null;
    }


    $meters =
        (float)
        $elevations[
            0
        ];


    return
        (int)
        round(
            $meters
            *
            3.28084
        );
}



/* =========================================================
   COMPLETE LOCATION LOOKUP
   ========================================================= */

function location_lookup(
    float $latitude,
    float $longitude
): array {

    if (
        !location_coordinates_valid(
            $latitude,
            $longitude
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid coordinates.'
        );
    }


    $geography =
        location_reverse_geocode(
            $latitude,
            $longitude
        );


    $elevationFeet =
        location_lookup_elevation_feet(
            $latitude,
            $longitude
        );


    return [

        'latitude' =>
            $latitude,

        'longitude' =>
            $longitude,

        'city' =>
            $geography[
                'city'
            ]
            ?? null,

        'state' =>
            $geography[
                'state'
            ]
            ?? null,

        'county' =>
            $geography[
                'county'
            ]
            ?? null,

        'elevationFeet' =>
            $elevationFeet,

        'displayName' =>
            $geography[
                'displayName'
            ]
            ?? null

    ];
}



/* =========================================================
   STRING HELPER
   ========================================================= */

function location_first_string(
    array $source,
    array $keys
): ?string {

    foreach (
        $keys as
        $key
    ) {

        if (
            !array_key_exists(
                $key,
                $source
            )
        ) {

            continue;
        }


        $value =
            trim(
                (string)
                $source[
                    $key
                ]
            );


        if (
            $value !== ''
        ) {

            return
                $value;
        }
    }


    return null;
}
