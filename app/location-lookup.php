<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   LOCATION LOOKUP SERVICE

   Takes exact coordinates and attempts to determine:

   - local named place / locality
   - state
   - county
   - road / display name
   - elevation

   Locality is intentionally separate from nearby.nearestTown.
   ========================================================= */


/* =========================================================
   ENDPOINTS
   ========================================================= */

const LLAMA_LOCATION_REVERSE_URL =
    'https://nominatim.openstreetmap.org/reverse';

const LLAMA_LOCATION_ELEVATION_URL =
    'https://api.open-meteo.com/v1/elevation';

const LLAMA_LOCATION_OVERPASS_URL =
    'https://overpass-api.de/api/interpreter';



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
                12,

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

   Best for road, county, state, and display name.
   It is NOT trusted to identify the nearest locality.
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
                    'geocodejson',

                'addressdetails' =>
                    1,

                'zoom' =>
                    18

            ]
        );


    $features =
        $data[
            'features'
        ]
        ?? [];


    $feature =
        (
            is_array(
                $features
            )
            &&
            isset(
                $features[
                    0
                ]
            )
            &&
            is_array(
                $features[
                    0
                ]
            )
        )
            ? $features[
                0
            ]
            : [];


    $properties =
        $feature[
            'properties'
        ][
            'geocoding'
        ]
        ?? [];


    if (
        !is_array(
            $properties
        )
    ) {

        $properties = [];
    }


    return [

        'state' =>
            location_first_string(
                $properties,
                [
                    'state'
                ]
            ),

        'county' =>
            location_first_string(
                $properties,
                [
                    'county'
                ]
            ),

        'road' =>
            location_first_string(
                $properties,
                [
                    'street',
                    'name'
                ]
            ),

        'displayName' =>
            isset(
                $properties[
                    'label'
                ]
            )
                ? trim(
                    (string)
                    $properties[
                        'label'
                    ]
                )
                : null

    ];
}



/* =========================================================
   LOCALITY LOOKUP

   Searches nearby named places using OpenStreetMap data.

   This is intentionally separate from nearby.nearestTown.
   ========================================================= */

function location_lookup_locality(
    float $latitude,
    float $longitude
): ?array {

    $query =
        sprintf(
            '[out:json][timeout:10];'
            .
            '('
            .
            'node["place"~"^(city|town|village|hamlet|locality)$"]'
            .
            '(around:25000,%F,%F);'
            .
            ');'
            .
            'out;',
            $latitude,
            $longitude
        );


    $data =
        location_http_get(
            LLAMA_LOCATION_OVERPASS_URL,
            [
                'data' =>
                    $query
            ]
        );


    $elements =
        $data[
            'elements'
        ]
        ?? [];


    if (
        !is_array(
            $elements
        )
        ||
        !$elements
    ) {

        return null;
    }


    $best =
        null;


    foreach (
        $elements as
        $element
    ) {

        if (
            !is_array(
                $element
            )
        ) {

            continue;
        }


        $tags =
            $element[
                'tags'
            ]
            ?? [];


        if (
            !is_array(
                $tags
            )
        ) {

            continue;
        }


        $name =
            trim(
                (string) (
                    $tags[
                        'name'
                    ]
                    ?? ''
                )
            );


        if (
            $name === ''
        ) {

            continue;
        }


        $placeType =
            trim(
                (string) (
                    $tags[
                        'place'
                    ]
                    ?? ''
                )
            );


        $placeLatitude =
            $element[
                'lat'
            ]
            ?? null;


        $placeLongitude =
            $element[
                'lon'
            ]
            ?? null;


        if (
            !is_numeric(
                $placeLatitude
            )
            ||
            !is_numeric(
                $placeLongitude
            )
        ) {

            continue;
        }


        $distance =
            location_distance_miles(
                $latitude,
                $longitude,
                (float)
                $placeLatitude,
                (float)
                $placeLongitude
            );


        if (
            $best === null
            ||
            $distance
            <
            $best[
                'distanceMiles'
            ]
        ) {

            $best = [

                'name' =>
                    $name,

                'type' =>
                    $placeType,

                'latitude' =>
                    (float)
                    $placeLatitude,

                'longitude' =>
                    (float)
                    $placeLongitude,

                'distanceMiles' =>
                    $distance

            ];
        }
    }


    return
        $best;
}



/* =========================================================
   DISTANCE
   ========================================================= */

function location_distance_miles(
    float $latitude1,
    float $longitude1,
    float $latitude2,
    float $longitude2
): float {

    $earthRadiusMiles =
        3958.7613;


    $lat1 =
        deg2rad(
            $latitude1
        );


    $lat2 =
        deg2rad(
            $latitude2
        );


    $deltaLat =
        deg2rad(
            $latitude2
            -
            $latitude1
        );


    $deltaLon =
        deg2rad(
            $longitude2
            -
            $longitude1
        );


    $a =
        sin(
            $deltaLat / 2
        )
        **
        2
        +
        cos(
            $lat1
        )
        *
        cos(
            $lat2
        )
        *
        sin(
            $deltaLon / 2
        )
        **
        2;


    $c =
        2
        *
        atan2(
            sqrt(
                $a
            ),
            sqrt(
                1 - $a
            )
        );


    return
        round(
            $earthRadiusMiles
            *
            $c,
            2
        );
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


    return
        (int)
        round(
            (float)
            $elevations[
                0
            ]
            *
            3.28084
        );
}



/* =========================================================
   COMPLETE LOOKUP
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


    /*
     * Locality is a separate nearby-place search.
     */

    try {

        $localityResult =
            location_lookup_locality(
                $latitude,
                $longitude
            );


    } catch (
        Throwable $error
    ) {

        /*
         * A failed locality lookup should not prevent the
         * rest of the location information from loading.
         */

        $localityResult =
            null;
    }


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

        'locality' =>
            $localityResult[
                'name'
            ]
            ?? null,

        'localityType' =>
            $localityResult[
                'type'
            ]
            ?? null,

        'localityDistanceMiles' =>
            $localityResult[
                'distanceMiles'
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

        'road' =>
            $geography[
                'road'
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
