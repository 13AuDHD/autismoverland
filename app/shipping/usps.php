<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT USPS ADAPTER

   USPS API v3

   Production:
   https://apis.usps.com

   Test:
   https://apis-tem.usps.com
   ========================================================= */


/* =========================================================
   USPS CONFIG
   ========================================================= */

function llama_shipping_usps_config(): array {

    $config =
        llama_shipping_config();


    $usps =
        $config[
            'carriers'
        ][
            'usps'
        ]
        ?? [];


    if (
        !is_array(
            $usps
        )
    ) {

        $usps =
            [];
    }


    return
        $usps;
}


/* =========================================================
   BASE URL
   ========================================================= */

function llama_shipping_usps_base_url(): string {

    $config =
        llama_shipping_usps_config();


    $environment =
        strtolower(
            trim(
                (string) (
                    $config[
                        'environment'
                    ]
                    ?? 'test'
                )
            )
        );


    return
        $environment ===
        'production'
            ? 'https://apis.usps.com'
            : 'https://apis-tem.usps.com';
}


/* =========================================================
   HTTP REQUEST
   ========================================================= */

function llama_shipping_usps_request(
    string $method,
    string $path,
    ?array $body = null,
    array $headers = []
): array {

    $url =
        rtrim(
            llama_shipping_usps_base_url(),
            '/'
        )
        .
        '/'
        .
        ltrim(
            $path,
            '/'
        );


    $curl =
        curl_init();


    if (
        $curl === false
    ) {

        throw new RuntimeException(
            'Could not initialize USPS request.'
        );
    }


    $requestHeaders = [

        'Accept: application/json',

        'Content-Type: application/json',

    ];


    foreach (
        $headers
        as
        $header
    ) {

        $requestHeaders[] =
            $header;
    }


    $options = [

        CURLOPT_URL =>
            $url,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_CONNECTTIMEOUT =>
            10,

        CURLOPT_TIMEOUT =>
            30,

        CURLOPT_CUSTOMREQUEST =>
            strtoupper(
                $method
            ),

        CURLOPT_HTTPHEADER =>
            $requestHeaders,

    ];


    if (
        $body !== null
    ) {

        $json =
            json_encode(
                $body,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            );


        if (
            $json === false
        ) {

            throw new RuntimeException(
                'Could not encode USPS request.'
            );
        }


        $options[
            CURLOPT_POSTFIELDS
        ] =
            $json;
    }


    curl_setopt_array(
        $curl,
        $options
    );


    $response =
        curl_exec(
            $curl
        );


    if (
        $response === false
    ) {

        $message =
            curl_error(
                $curl
            );


        curl_close(
            $curl
        );


        throw new RuntimeException(
            'USPS request failed: '
            .
            $message
        );
    }


    $status =
        (int)
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $curl
    );


    $decoded =
        json_decode(
            (string)
            $response,
            true
        );


    if (
        !is_array(
            $decoded
        )
    ) {

        throw new RuntimeException(
            'USPS returned an unreadable response.'
        );
    }


    if (
        $status < 200
        ||
        $status >= 300
    ) {

        $message =
            llama_shipping_usps_error_message(
                $decoded
            );


        throw new RuntimeException(
            'USPS API error'
            .
            (
                $message !== ''
                    ? ': '
                      .
                      $message
                    : '.'
            )
        );
    }


    return
        $decoded;
}


/* =========================================================
   ERROR MESSAGE
   ========================================================= */

function llama_shipping_usps_error_message(
    array $response
): string {

    $candidates = [

        $response[
            'error'
        ][
            'message'
        ]
        ?? null,

        $response[
            'message'
        ]
        ?? null,

        $response[
            'detail'
        ]
        ?? null,

        $response[
            'title'
        ]
        ?? null,

    ];


    foreach (
        $candidates
        as
        $candidate
    ) {

        if (
            is_string(
                $candidate
            )
            &&
            trim(
                $candidate
            )
            !==
            ''
        ) {

            return
                trim(
                    $candidate
                );
        }
    }


    return
        '';
}


/* =========================================================
   OAUTH TOKEN
   ========================================================= */

function llama_shipping_usps_token(): string {

    static $token =
        '';

    static $expiresAt =
        0;


    if (
        $token !== ''
        &&
        $expiresAt >
        time()
        +
        60
    ) {

        return
            $token;
    }


    $config =
        llama_shipping_usps_config();


    $clientId =
        trim(
            (string) (
                $config[
                    'client_id'
                ]
                ?? ''
            )
        );


    $clientSecret =
        trim(
            (string) (
                $config[
                    'client_secret'
                ]
                ?? ''
            )
        );


    if (
        $clientId === ''
        ||
        $clientSecret === ''
    ) {

        throw new RuntimeException(
            'USPS API credentials are not configured.'
        );
    }


    $response =
        llama_shipping_usps_request(
            'POST',
            '/oauth2/v3/token',
            [

                'client_id' =>
                    $clientId,

                'client_secret' =>
                    $clientSecret,

                'grant_type' =>
                    'client_credentials',

            ]
        );


    $token =
        trim(
            (string) (
                $response[
                    'access_token'
                ]
                ?? ''
            )
        );


    if (
        $token === ''
    ) {

        throw new RuntimeException(
            'USPS did not return an OAuth access token.'
        );
    }


    $expiresIn =
        max(
            60,
            (int) (
                $response[
                    'expires_in'
                ]
                ?? 3600
            )
        );


    $expiresAt =
        time()
        +
        $expiresIn;


    return
        $token;
}


/* =========================================================
   SHIPPING OPTIONS REQUEST
   ========================================================= */

function llama_shipping_usps_rates(
    array $request
): array {

    $originPostal =
        llama_shipping_usps_zip(
            $request[
                'origin_postal_code'
            ]
            ?? ''
        );


    $destinationPostal =
        llama_shipping_usps_zip(
            $request[
                'destination_postal_code'
            ]
            ?? ''
        );


    if (
        $originPostal === ''
        ||
        $destinationPostal === ''
    ) {

        throw new InvalidArgumentException(
            'USPS requires origin and destination ZIP Codes.'
        );
    }


    $weightOz =
        (float) (
            $request[
                'weight_oz'
            ]
            ?? 0
        );


    if (
        $weightOz <= 0
    ) {

        throw new InvalidArgumentException(
            'USPS requires package weight.'
        );
    }


    /*
     * USPS Shipping Options accepts package weight in pounds.
     */

    $weightLb =
        round(
            $weightOz / 16,
            4
        );


    $length =
        llama_shipping_usps_dimension(
            $request[
                'length_in'
            ]
            ?? null
        );


    $width =
        llama_shipping_usps_dimension(
            $request[
                'width_in'
            ]
            ?? null
        );


    $height =
        llama_shipping_usps_dimension(
            $request[
                'height_in'
            ]
            ?? null
        );


    $girth =
        llama_shipping_usps_dimension(
            $request[
                'girth_in'
            ]
            ?? null
        );


    $config =
        llama_shipping_usps_config();


    $priceType =
        strtoupper(
            trim(
                (string) (
                    $config[
                        'price_type'
                    ]
                    ?? 'RETAIL'
                )
            )
        );


    $pricingOption = [

        'priceType' =>
            $priceType,

    ];


    $accountType =
        trim(
            (string) (
                $config[
                    'account_type'
                ]
                ?? ''
            )
        );


    $accountNumber =
        trim(
            (string) (
                $config[
                    'account_number'
                ]
                ?? ''
            )
        );


    if (
        $accountType !== ''
        &&
        $accountNumber !== ''
    ) {

        $pricingOption[
            'paymentAccount'
        ] = [

            'accountType' =>
                $accountType,

            'accountNumber' =>
                $accountNumber,

        ];
    }


    $packageDescription = [

        'weight' =>
            $weightLb,

        /*
         * MAILING_DATE may affect pricing.
         */

        'mailingDate' =>
            date(
                'Y-m-d'
            ),

    ];


    if (
        $length !== null
    ) {

        $packageDescription[
            'length'
        ] =
            $length;
    }


    if (
        $width !== null
    ) {

        $packageDescription[
            'width'
        ] =
            $width;
    }


    if (
        $height !== null
    ) {

        $packageDescription[
            'height'
        ] =
            $height;
    }


    if (
        $girth !== null
    ) {

        $packageDescription[
            'girth'
        ] =
            $girth;
    }


    $mailClass =
        trim(
            (string) (
                $request[
                    'mail_class'
                ]
                ?? ''
            )
        );


    if (
        $mailClass !== ''
    ) {

        $packageDescription[
            'mailClass'
        ] =
            strtoupper(
                $mailClass
            );
    }


    $processingCategory =
        trim(
            (string) (
                $request[
                    'processing_category'
                ]
                ?? ''
            )
        );


    if (
        $processingCategory !== ''
    ) {

        $packageDescription[
            'processingCategory'
        ] =
            strtoupper(
                $processingCategory
            );
    }


    $destinationEntryFacilityType =
        trim(
            (string) (
                $request[
                    'destination_entry_facility_type'
                ]
                ?? ''
            )
        );


    if (
        $destinationEntryFacilityType !== ''
    ) {

        $packageDescription[
            'destinationEntryFacilityType'
        ] =
            strtoupper(
                $destinationEntryFacilityType
            );
    }


    $payload = [

        'pricingOptions' => [
            $pricingOption
        ],

        'originZIPCode' =>
            $originPostal,

        'destinationZIPCode' =>
            $destinationPostal,

        'packageDescription' =>
            $packageDescription,

    ];


    $token =
        llama_shipping_usps_token();


    $response =
        llama_shipping_usps_request(
            'POST',
            '/shipments/v3/options/search',
            $payload,
            [

                'Authorization: Bearer '
                .
                $token,

            ]
        );


    return
        llama_shipping_usps_normalize_rates(
            $response
        );
}


/* =========================================================
   NORMALIZE ZIP
   ========================================================= */

function llama_shipping_usps_zip(
    mixed $value
): string {

    $value =
        preg_replace(
            '/[^0-9]/',
            '',
            (string)
            $value
        )
        ?? '';


    if (
        strlen(
            $value
        )
        >=
        5
    ) {

        return
            substr(
                $value,
                0,
                5
            );
    }


    return
        '';
}


/* =========================================================
   DIMENSION
   ========================================================= */

function llama_shipping_usps_dimension(
    mixed $value
): ?float {

    if (
        $value === null
        ||
        trim(
            (string)
            $value
        )
        ===
        ''
    ) {

        return null;
    }


    if (
        !is_numeric(
            $value
        )
    ) {

        throw new InvalidArgumentException(
            'USPS package dimensions must be numeric.'
        );
    }


    $value =
        (float)
        $value;


    if (
        $value <= 0
    ) {

        return null;
    }


    return
        round(
            $value,
            2
        );
}


/* =========================================================
   NORMALIZE USPS RATES

   USPS can add fields over time. This parser looks for
   recognizable service/price records while preserving each
   original response record under "raw".
   ========================================================= */

function llama_shipping_usps_normalize_rates(
    array $response
): array {

    $records =
        [];


    llama_shipping_usps_collect_rate_records(
        $response,
        $records
    );


    $normalized =
        [];


    foreach (
        $records
        as
        $record
    ) {

        $price =
            llama_shipping_usps_find_number(
                $record,
                [
                    'totalPrice',
                    'totalBasePrice',
                    'price',
                    'postage',
                    'rate',
                ]
            );


        if (
            $price === null
            ||
            $price < 0
        ) {

            continue;
        }


        $serviceCode =
            llama_shipping_usps_find_string(
                $record,
                [
                    'SKU',
                    'sku',
                    'mailClass',
                    'serviceCode',
                    'productId',
                    'productID',
                ]
            );


        $serviceName =
            llama_shipping_usps_find_string(
                $record,
                [
                    'productName',
                    'serviceName',
                    'mailClassDescription',
                    'name',
                    'description',
                ]
            );


        if (
            $serviceCode === ''
            &&
            $serviceName === ''
        ) {

            continue;
        }


        $deliveryDays =
            llama_shipping_usps_find_integer(
                $record,
                [
                    'deliveryDays',
                    'serviceStandard',
                    'days',
                ]
            );


        $deliveryDate =
            llama_shipping_usps_find_string(
                $record,
                [
                    'deliveryDate',
                    'estimatedDeliveryDate',
                    'scheduledDeliveryDate',
                ]
            );


        $key =
            strtolower(
                $serviceCode
                .
                '|'
                .
                $serviceName
                .
                '|'
                .
                number_format(
                    $price,
                    2,
                    '.',
                    ''
                )
            );


        $normalized[
            $key
        ] = [

            'carrier' =>
                LLAMA_SHIPPING_CARRIER_USPS,

            'service_code' =>
                $serviceCode !== ''
                    ? $serviceCode
                    : $serviceName,

            'service_name' =>
                $serviceName !== ''
                    ? $serviceName
                    : $serviceCode,

            'amount_cents' =>
                (int)
                round(
                    $price * 100
                ),

            'currency' =>
                'usd',

            'delivery_days' =>
                $deliveryDays,

            'delivery_date' =>
                $deliveryDate !== ''
                    ? $deliveryDate
                    : null,

            'raw' =>
                $record,

        ];
    }


    $normalized =
        array_values(
            $normalized
        );


    usort(
        $normalized,
        static function (
            array $a,
            array $b
        ): int {

            return
                $a[
                    'amount_cents'
                ]
                <=>
                $b[
                    'amount_cents'
                ];
        }
    );


    if (
        !$normalized
    ) {

        throw new RuntimeException(
            'USPS returned no usable shipping rates.'
        );
    }


    return
        $normalized;
}


/* =========================================================
   FIND RATE-LIKE RECORDS
   ========================================================= */

function llama_shipping_usps_collect_rate_records(
    array $node,
    array &$records
): void {

    $hasPrice =
        llama_shipping_usps_find_number(
            $node,
            [
                'totalPrice',
                'totalBasePrice',
                'price',
                'postage',
                'rate',
            ]
        )
        !==
        null;


    $hasService =
        llama_shipping_usps_find_string(
            $node,
            [
                'SKU',
                'sku',
                'mailClass',
                'serviceCode',
                'productName',
                'serviceName',
                'name',
            ]
        )
        !==
        '';


    if (
        $hasPrice
        &&
        $hasService
    ) {

        $records[] =
            $node;
    }


    foreach (
        $node
        as
        $value
    ) {

        if (
            is_array(
                $value
            )
        ) {

            llama_shipping_usps_collect_rate_records(
                $value,
                $records
            );
        }
    }
}


/* =========================================================
   RESPONSE HELPERS
   ========================================================= */

function llama_shipping_usps_find_string(
    array $record,
    array $keys
): string {

    foreach (
        $keys
        as
        $key
    ) {

        if (
            array_key_exists(
                $key,
                $record
            )
            &&
            is_scalar(
                $record[
                    $key
                ]
            )
        ) {

            $value =
                trim(
                    (string)
                    $record[
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
    }


    return
        '';
}


function llama_shipping_usps_find_number(
    array $record,
    array $keys
): ?float {

    foreach (
        $keys
        as
        $key
    ) {

        if (
            array_key_exists(
                $key,
                $record
            )
            &&
            is_numeric(
                $record[
                    $key
                ]
            )
        ) {

            return
                (float)
                $record[
                    $key
                ];
        }
    }


    return null;
}


function llama_shipping_usps_find_integer(
    array $record,
    array $keys
): ?int {

    $number =
        llama_shipping_usps_find_number(
            $record,
            $keys
        );


    return
        $number !== null
            ? (int)
              round(
                  $number
              )
            : null;
}
