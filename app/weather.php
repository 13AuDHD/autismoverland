<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   WEATHER SERVICE
   app/weather.php

   Free / Visitor:
   Weather is based on the public city + state.

   Member:
   Weather is based on exact campsite coordinates
   and recorded campsite elevation.
   ========================================================= */


/* =========================================================
   CONSTANTS
   ========================================================= */

const LLAMA_WEATHER_FORECAST_URL =
    'https://api.open-meteo.com/v1/forecast';

const LLAMA_WEATHER_GEOCODING_URL =
    'https://geocoding-api.open-meteo.com/v1/search';



/* =========================================================
   HTTP REQUEST
   ========================================================= */

function weather_http_get(
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
            'Could not initialize weather request.'
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

            CURLOPT_USERAGENT =>
                'LlamaScout/1.0 (+https://llamascout.com)',

            CURLOPT_HTTPHEADER =>
                [
                    'Accept: application/json'
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
                ? 'Weather request failed: ' . $error
                : 'Weather request returned HTTP ' . $status . '.'
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
            'Weather service returned invalid JSON.'
        );
    }


    if (
        !empty(
            $decoded[
                'error'
            ]
        )
    ) {

        throw new RuntimeException(
            (string) (
                $decoded[
                    'reason'
                ]
                ??
                'Weather service returned an error.'
            )
        );
    }


    return
        $decoded;
}



/* =========================================================
   CITY GEOCODING

   Used only for visitor/free weather.

   Example:
       Pagosa Springs, Colorado

   The actual campsite coordinates are NOT involved.
   ========================================================= */

function weather_geocode_city(
    string $city,
    string $state
): ?array {

    $city =
        trim(
            $city
        );


    $state =
        trim(
            $state
        );


    if (
        $city === ''
        ||
        $state === ''
    ) {

        return null;
    }


    $search =
        $city
        .
        ', '
        .
        $state
        .
        ', United States';


    $data =
        weather_http_get(
            LLAMA_WEATHER_GEOCODING_URL,
            [
                'name' =>
                    $search,

                'count' =>
                    5,

                'language' =>
                    'en',

                'format' =>
                    'json',

                'countryCode' =>
                    'US'
            ]
        );


    $results =
        $data[
            'results'
        ]
        ?? [];


    if (
        !is_array(
            $results
        )
        ||
        !$results
    ) {

        return null;
    }


    /*
     * Prefer an exact city + state match.
     */

    foreach (
        $results as
        $result
    ) {

        if (
            !is_array(
                $result
            )
        ) {
            continue;
        }


        $resultName =
            trim(
                (string) (
                    $result[
                        'name'
                    ]
                    ??
                    ''
                )
            );


        $resultState =
            trim(
                (string) (
                    $result[
                        'admin1'
                    ]
                    ??
                    ''
                )
            );


        if (
            strcasecmp(
                $resultName,
                $city
            ) === 0
            &&
            strcasecmp(
                $resultState,
                $state
            ) === 0
        ) {

            return
                weather_normalize_geocoded_location(
                    $result
                );
        }
    }


    /*
     * If Open-Meteo does not return an exact textual match,
     * use its highest-ranked result.
     */

    $first =
        $results[
            0
        ]
        ?? null;


    if (
        !is_array(
            $first
        )
    ) {

        return null;
    }


    return
        weather_normalize_geocoded_location(
            $first
        );
}



function weather_normalize_geocoded_location(
    array $result
): ?array {

    $latitude =
        $result[
            'latitude'
        ]
        ?? null;


    $longitude =
        $result[
            'longitude'
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

        return null;
    }


    return [
        'latitude' =>
            (float)
            $latitude,

        'longitude' =>
            (float)
            $longitude,

        'elevationMeters' =>
            isset(
                $result[
                    'elevation'
                ]
            )
            &&
            is_numeric(
                $result[
                    'elevation'
                ]
            )
                ? (float)
                  $result[
                      'elevation'
                  ]
                : null,

        'name' =>
            (
                isset(
                    $result[
                        'name'
                    ]
                )
                &&
                trim(
                    (string)
                    $result[
                        'name'
                    ]
                ) !== ''
            )
                ? trim(
                    (string)
                    $result[
                        'name'
                    ]
                )
                : null,

        'state' =>
            (
                isset(
                    $result[
                        'admin1'
                    ]
                )
                &&
                trim(
                    (string)
                    $result[
                        'admin1'
                    ]
                ) !== ''
            )
                ? trim(
                    (string)
                    $result[
                        'admin1'
                    ]
                )
                : null,

        'timezone' =>
            (
                isset(
                    $result[
                        'timezone'
                    ]
                )
                &&
                trim(
                    (string)
                    $result[
                        'timezone'
                    ]
                ) !== ''
            )
                ? trim(
                    (string)
                    $result[
                        'timezone'
                    ]
                )
                : null
    ];
}



/* =========================================================
   FEET TO METERS

   Llama Scout stores elevation in feet.
   Open-Meteo expects elevation in meters.
   ========================================================= */

function weather_feet_to_meters(
    int|float|null $feet
): ?float {

    if (
        $feet === null
    ) {

        return null;
    }


    return
        round(
            (float)
            $feet
            *
            0.3048,
            1
        );
}



/* =========================================================
   FORECAST REQUEST

   This one request contains enough information for:

   - Sidebar current weather
   - Today's high / low
   - Precipitation
   - Wind
   - Hourly forecast
   - Extended forecast
   - Sunrise / sunset

   We can decide later how much of it each access level sees.
   ========================================================= */

function weather_fetch_forecast(
    float $latitude,
    float $longitude,
    ?float $elevationMeters = null,
    int $forecastDays = 7
): array {

    /*
     * Keep our requested forecast inside the supported range.
     */

    $forecastDays =
        max(
            1,
            min(
                16,
                $forecastDays
            )
        );


    $parameters = [

        'latitude' =>
            $latitude,

        'longitude' =>
            $longitude,


        /*
         * Automatically return timestamps in the
         * forecast location's local timezone.
         */

        'timezone' =>
            'auto',


        /*
         * US display units.
         */

        'temperature_unit' =>
            'fahrenheit',

        'wind_speed_unit' =>
            'mph',

        'precipitation_unit' =>
            'inch',


        /*
         * CURRENT CONDITIONS
         */

        'current' =>
            implode(
                ',',
                [
                    'temperature_2m',
                    'apparent_temperature',
                    'relative_humidity_2m',
                    'precipitation',
                    'weather_code',
                    'cloud_cover',
                    'wind_speed_10m',
                    'wind_direction_10m',
                    'wind_gusts_10m',
                    'is_day'
                ]
            ),


        /*
         * HOURLY FORECAST
         */

        'hourly' =>
            implode(
                ',',
                [
                    'temperature_2m',
                    'apparent_temperature',
                    'relative_humidity_2m',
                    'precipitation_probability',
                    'precipitation',
                    'rain',
                    'showers',
                    'snowfall',
                    'weather_code',
                    'cloud_cover',
                    'visibility',
                    'wind_speed_10m',
                    'wind_direction_10m',
                    'wind_gusts_10m'
                ]
            ),


        /*
         * DAILY / EXTENDED FORECAST
         */

        'daily' =>
            implode(
                ',',
                [
                    'weather_code',
                    'temperature_2m_max',
                    'temperature_2m_min',
                    'apparent_temperature_max',
                    'apparent_temperature_min',
                    'sunrise',
                    'sunset',
                    'precipitation_sum',
                    'rain_sum',
                    'showers_sum',
                    'snowfall_sum',
                    'precipitation_hours',
                    'precipitation_probability_max',
                    'wind_speed_10m_max',
                    'wind_gusts_10m_max',
                    'wind_direction_10m_dominant'
                ]
            ),


        'forecast_days' =>
            $forecastDays
    ];


    /*
     * For the exact campsite forecast, use Llama Scout's
     * recorded campsite elevation instead of allowing the
     * weather model to choose its own terrain elevation.
     *
     * For city weather, this can be left null and Open-Meteo
     * will use its normal terrain model.
     */

    if (
        $elevationMeters !== null
    ) {

        $parameters[
            'elevation'
        ] =
            $elevationMeters;
    }


    return
        weather_http_get(
            LLAMA_WEATHER_FORECAST_URL,
            $parameters
        );
}



/* =========================================================
   FREE / VISITOR FORECAST

   Uses city + state only.

   Exact campsite coordinates and campsite elevation
   are intentionally not accepted by this function.
   ========================================================= */

function weather_fetch_city_forecast(
    string $city,
    string $state,
    int $forecastDays = 7
): array {

    $location =
        weather_geocode_city(
            $city,
            $state
        );


    if (
        $location === null
    ) {

        throw new RuntimeException(
            'Could not locate the nearby city for weather.'
        );
    }


    $forecast =
        weather_fetch_forecast(
            $location[
                'latitude'
            ],
            $location[
                'longitude'
            ],
            null,
            $forecastDays
        );


    return [
        'type' =>
            'city',

        'location' =>
            [
                'city' =>
                    $city,

                'state' =>
                    $state,

                /*
                 * These are the CITY coordinates returned
                 * by Open-Meteo, not campsite coordinates.
                 */

                'latitude' =>
                    $location[
                        'latitude'
                    ],

                'longitude' =>
                    $location[
                        'longitude'
                    ]
            ],

        'forecast' =>
            $forecast
    ];
}



/* =========================================================
   EXACT CAMPSITE FORECAST

   This must only be called after membership access has
   already been confirmed server-side.
   ========================================================= */

function weather_fetch_campsite_forecast(
    float $latitude,
    float $longitude,
    int|float|null $elevationFeet,
    int $forecastDays = 7
): array {

    $elevationMeters =
        weather_feet_to_meters(
            $elevationFeet
        );


    $forecast =
        weather_fetch_forecast(
            $latitude,
            $longitude,
            $elevationMeters,
            $forecastDays
        );


    return [
        'type' =>
            'campsite',

        'location' =>
            [
                'latitude' =>
                    $latitude,

                'longitude' =>
                    $longitude,

                'elevationFeet' =>
                    $elevationFeet,

                'elevationMeters' =>
                    $elevationMeters
            ],

        'forecast' =>
            $forecast
    ];
}
